<?php

namespace Database\Seeders;

use App\Models\ContentCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        collect([
            ['name' => 'Pornography', 'slug' => 'pornography', 'default_severity' => 'high', 'is_high_risk' => true],
            ['name' => 'Gambling', 'slug' => 'gambling', 'default_severity' => 'high', 'is_high_risk' => true],
            ['name' => 'Malware and Phishing', 'slug' => 'malware-phishing', 'default_severity' => 'critical', 'is_high_risk' => true],
            ['name' => 'Proxy and Anonymizers', 'slug' => 'proxy-anonymizers', 'default_severity' => 'high', 'is_high_risk' => true],
            ['name' => 'Social Media', 'slug' => 'social-media', 'default_severity' => 'medium', 'is_high_risk' => false],
            ['name' => 'Streaming', 'slug' => 'streaming', 'default_severity' => 'low', 'is_high_risk' => false],
            ['name' => 'Games', 'slug' => 'games', 'default_severity' => 'low', 'is_high_risk' => false],
            ['name' => 'Artificial Intelligence Tools', 'slug' => 'ai-tools', 'default_severity' => 'medium', 'is_high_risk' => false],
        ])->each(fn (array $category) => ContentCategory::query()->updateOrCreate(
            ['slug' => $category['slug']],
            $category + ['counts_toward_incidents' => true],
        ));
    }
}
