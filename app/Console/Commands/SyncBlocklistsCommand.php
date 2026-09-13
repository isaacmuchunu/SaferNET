<?php

namespace App\Console\Commands;

use App\Models\BlocklistSource;
use App\Services\Blocklists\BlocklistSynchroniser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('safernet:sync-blocklists {--source= : Synchronise a single source by slug} {--all : Include sources that are switched off}')]
#[Description('Fetch the upstream domain blocklists and refresh the county filtering data')]
class SyncBlocklistsCommand extends Command
{
    public function handle(BlocklistSynchroniser $synchroniser): int
    {
        $sources = BlocklistSource::query()
            ->when($this->option('source'), fn ($query, $slug) => $query->where('slug', $slug))
            ->when(! $this->option('all') && ! $this->option('source'), fn ($query) => $query->enabled())
            ->orderBy('name')
            ->get();

        if ($sources->isEmpty()) {
            $this->components->warn('No blocklist sources are registered. Run the BlocklistSourceSeeder first.');

            return self::FAILURE;
        }

        $failures = 0;

        foreach ($sources as $source) {
            $this->components->task($source->name, function () use ($synchroniser, $source, &$failures): bool {
                try {
                    $result = $synchroniser->sync($source);

                    $this->line(sprintf(
                        '  <fg=gray>%s domains · %s</>',
                        number_format($result['domains']),
                        $result['unchanged'] ? 'unchanged upstream' : 'refreshed',
                    ));

                    return true;
                } catch (Throwable $exception) {
                    $failures++;
                    $this->line('  <fg=red>'.$exception->getMessage().'</>');

                    return false;
                }
            });
        }

        $total = BlocklistSource::query()->sum('domains_count');
        $this->newLine();
        $this->components->info(sprintf('%s domains are blocked across %d sources.', number_format($total), $sources->count()));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
