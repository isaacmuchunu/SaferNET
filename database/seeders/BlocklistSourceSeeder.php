<?php

namespace Database\Seeders;

use App\Models\BlocklistSource;
use App\Models\ContentCategory;
use Illuminate\Database\Seeder;

/**
 * Registers the upstream blocklist catalogue. Registration is not
 * synchronisation: every source starts with no domains and no last-synced time
 * until `php artisan safernet:sync-blocklists` has actually fetched it.
 */
class BlocklistSourceSeeder extends Seeder
{
    public function run(): void
    {
        $categories = ContentCategory::query()->pluck('id', 'slug');

        foreach (config('blocklists.sources', []) as $source) {
            BlocklistSource::query()->updateOrCreate(
                ['slug' => $source['slug']],
                [
                    'name' => $source['name'],
                    'url' => $source['url'],
                    'content_category_id' => $categories[$source['category']] ?? null,
                    'description' => $source['description'],
                    'provenance' => $source['provenance'],
                    'is_enabled' => $source['enabled'],
                ],
            );
        }

        $this->command?->info(sprintf('Registered %d blocklist sources.', BlocklistSource::query()->count()));
    }
}
