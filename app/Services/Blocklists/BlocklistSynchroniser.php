<?php

namespace App\Services\Blocklists;

use App\Models\BlocklistSource;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fetches one upstream list, parses it, and replaces that source's domains.
 *
 * The counts an officer reads come from here and nowhere else: a source that
 * failed to fetch keeps its previous count and carries the error, rather than
 * reporting protection the school does not have.
 */
class BlocklistSynchroniser
{
    /** Chunk size for the domain upsert — large enough to be quick, small enough for the driver. */
    private const INSERT_CHUNK = 2_000;

    public function __construct(private readonly SafeFetcher $fetcher) {}

    /**
     * @return array{domains: int, bytes: int, unchanged: bool}
     */
    public function sync(BlocklistSource $source): array
    {
        try {
            $fetched = $this->fetcher->get($source->url);
        } catch (Throwable $exception) {
            $source->forceFill([
                'last_status' => 'failed',
                'last_error' => $exception->getMessage(),
                'last_synced_at' => now(),
            ])->save();

            throw $exception;
        }

        $checksum = hash('sha256', $fetched['body']);

        if ($source->checksum === $checksum) {
            $source->forceFill(['last_status' => 'unchanged', 'last_error' => null, 'last_synced_at' => now()])->save();

            return ['domains' => $source->domains_count, 'bytes' => $fetched['bytes'], 'unchanged' => true];
        }

        $domains = $this->parse($fetched['body']);

        DB::transaction(function () use ($source, $domains, $fetched, $checksum): void {
            $source->domains()->delete();

            foreach (array_chunk($domains, self::INSERT_CHUNK) as $chunk) {
                $source->domains()->insert(array_map(
                    fn (string $domain): array => [
                        'blocklist_source_id' => $source->id,
                        'domain' => $domain,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    $chunk,
                ));
            }

            $source->forceFill([
                'domains_count' => count($domains),
                'bytes_fetched' => $fetched['bytes'],
                'checksum' => $checksum,
                'last_status' => 'synced',
                'last_error' => null,
                'last_synced_at' => now(),
            ])->save();
        });

        return ['domains' => count($domains), 'bytes' => $fetched['bytes'], 'unchanged' => false];
    }

    /**
     * Accepts both formats these projects publish: a hosts file
     * ("0.0.0.0 example.com") and a bare domain-per-line list.
     *
     * @return list<string>
     */
    public function parse(string $body): array
    {
        $domains = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $fields = preg_split('/\s+/', $line) ?: [];
            $candidate = count($fields) > 1 ? $fields[1] : $fields[0];
            $candidate = strtolower(trim($candidate));

            // Hosts files carry these as the loopback entry, not as a blocked site.
            if (in_array($candidate, ['localhost', 'localhost.localdomain', 'broadcasthost', 'ip6-localhost', 'ip6-loopback'], true)) {
                continue;
            }

            if (preg_match('/^(?=.{1,253}$)([a-z0-9](-*[a-z0-9])*\.)+[a-z]{2,}$/', $candidate) !== 1) {
                continue;
            }

            $domains[$candidate] = true;
        }

        return array_keys($domains);
    }
}
