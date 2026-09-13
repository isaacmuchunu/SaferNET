<?php

namespace App\Services\Blocklists;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Outbound fetch with SSRF containment.
 *
 * A blocklist URL is fetched by the server on an officer's instruction, so
 * without these checks the sync endpoint is a request forgery primitive: point
 * it at 169.254.169.254 and the cloud metadata service — credentials included —
 * is read back as "blocklist content".
 *
 * In order: https only, host allowlist, every resolved address checked against
 * private, loopback and link-local ranges, redirects refused outright (a
 * redirect is a second, unvalidated request), a hard byte cap, and a timeout.
 */
class SafeFetcher
{
    /**
     * @param  list<string>  $allowedHosts
     */
    public function __construct(
        private readonly array $allowedHosts,
        private readonly int $maxBytes,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('blocklists.allowed_hosts', ['raw.githubusercontent.com']),
            (int) config('blocklists.max_bytes', 8 * 1024 * 1024),
            (int) config('blocklists.timeout', 60),
        );
    }

    /**
     * @return array{body: string, bytes: int}
     *
     * @throws RuntimeException when the URL is not one this deployment may fetch.
     */
    public function get(string $url): array
    {
        $this->assertFetchable($url);

        $response = Http::withoutRedirecting()
            ->timeout($this->timeout)
            ->withHeaders(['User-Agent' => 'SAFERNET/1.0 (+Kiambu County Directorate of Education)'])
            ->get($url);

        if ($response->redirect()) {
            throw new RuntimeException('The source answered with a redirect, which is a second unvalidated request and is refused.');
        }

        if (! $response->successful()) {
            throw new RuntimeException("The source answered {$response->status()}.");
        }

        $body = $response->body();
        $bytes = strlen($body);

        if ($bytes > $this->maxBytes) {
            throw new RuntimeException(sprintf(
                'The source returned %s, over the %s limit for a single list.',
                $this->humanBytes($bytes),
                $this->humanBytes($this->maxBytes),
            ));
        }

        return ['body' => $body, 'bytes' => $bytes];
    }

    public function assertFetchable(string $url): void
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https') {
            throw new RuntimeException('Only https sources may be fetched.');
        }

        $host = strtolower($parts['host'] ?? '');

        if (! in_array($host, array_map('strtolower', $this->allowedHosts), true)) {
            throw new RuntimeException("The host {$host} is not on the blocklist source allowlist.");
        }

        foreach ($this->resolve($host) as $address) {
            if ($this->isBlockedAddress($address)) {
                throw new RuntimeException("The host {$host} resolves to {$address}, which is not a public address.");
            }
        }
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $addresses = array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));

        if ($addresses === []) {
            throw new RuntimeException("The host {$host} does not resolve.");
        }

        return $addresses;
    }

    /**
     * 169.254.169.254 is the cloud instance metadata endpoint: on most providers
     * it hands credentials to anything that can make an HTTP request from the host.
     */
    public function isBlockedAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            [$a, $b] = array_map('intval', explode('.', $address));

            return $a === 0
                || $a === 10
                || $a === 127
                || ($a === 169 && $b === 254)
                || ($a === 172 && $b >= 16 && $b <= 31)
                || ($a === 192 && $b === 168)
                || ($a === 192 && $b === 0)
                || ($a === 100 && $b >= 64 && $b <= 127)
                || $a >= 224;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalised = strtolower($address);

            if (in_array($normalised, ['::', '::1'], true)) {
                return true;
            }

            if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $normalised, $matches) === 1) {
                return $this->isBlockedAddress($matches[1]);
            }

            return (bool) preg_match('/^(fe80|fec0|fc|fd|ff)/', $normalised);
        }

        // Not an IP literal: refuse rather than guess.
        return true;
    }

    private function humanBytes(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 2).' MB';
    }
}
