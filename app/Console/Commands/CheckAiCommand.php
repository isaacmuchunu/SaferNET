<?php

namespace App\Console\Commands;

use App\Services\Ai\ContentClassifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('safernet:check-ai {url=https://example.com : URL to classify} {--query= : Optional search query context}')]
#[Description('Verify the configured AI classification provider with a live, non-authoritative check')]
class CheckAiCommand extends Command
{
    public function handle(ContentClassifier $classifier): int
    {
        $providers = array_map(fn ($provider) => $provider->name(), $classifier->available());

        if ($providers === []) {
            $this->components->error('No AI provider is configured. Set a Gemini, Anthropic, or OpenAI API key.');

            return self::FAILURE;
        }

        $this->components->info('Configured providers: '.implode(', ', $providers));
        $assessment = $classifier->classify((string) $this->argument('url'), (string) $this->option('query'));

        if ($assessment === null) {
            $this->components->error('The classifier returned no usable assessment. Review the application log and provider credentials.');

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], collect($assessment->toArray())->map(fn ($value, $key) => [
            $key,
            is_scalar($value) || $value === null ? (string) $value : json_encode($value),
        ])->values()->all());

        return self::SUCCESS;
    }
}
