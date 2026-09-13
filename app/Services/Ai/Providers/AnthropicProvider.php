<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\ClassifierPrompt;
use App\Services\Ai\ClassifierProvider;
use App\Services\Ai\ContentAssessment;
use App\Services\Ai\ModelUnavailable;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements ClassifierProvider
{
    /**
     * @param  list<string>  $models
     */
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $endpoint,
        private readonly string $version,
        private readonly array $models,
        private readonly int $timeout,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey) && $this->models !== [];
    }

    public function models(): array
    {
        return $this->models;
    }

    public function classify(string $model, string $url, string $query): ?ContentAssessment
    {
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->version,
            ])
            ->post($this->endpoint, [
                'model' => $model,
                'max_tokens' => 400,
                'temperature' => 0,
                'system' => ClassifierPrompt::system(),
                'messages' => [['role' => 'user', 'content' => ClassifierPrompt::user($url, $query)]],
            ]);

        if ($response->status() === 404 || str_contains(strtolower((string) $response->json('error.message')), 'not_found')) {
            throw new ModelUnavailable("Anthropic model {$model} is not available.");
        }

        if ($response->failed()) {
            return null;
        }

        $text = collect($response->json('content', []))
            ->firstWhere('type', 'text')['text'] ?? null;

        if (! is_string($text)) {
            return null;
        }

        $decoded = ClassifierPrompt::decode($text);

        return $decoded === null ? null : ContentAssessment::fromModelOutput($decoded, $this->name(), $model);
    }
}
