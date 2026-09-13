<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\ClassifierPrompt;
use App\Services\Ai\ClassifierProvider;
use App\Services\Ai\ContentAssessment;
use App\Services\Ai\ModelUnavailable;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements ClassifierProvider
{
    /**
     * @param  list<string>  $models
     */
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $endpoint,
        private readonly array $models,
        private readonly int $timeout,
    ) {}

    public function name(): string
    {
        return 'openai';
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
            ->withToken($this->apiKey)
            ->post($this->endpoint, [
                'model' => $model,
                'temperature' => 0,
                'max_tokens' => 400,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => ClassifierPrompt::system()],
                    ['role' => 'user', 'content' => ClassifierPrompt::user($url, $query)],
                ],
            ]);

        if ($response->status() === 404 || $response->json('error.code') === 'model_not_found') {
            throw new ModelUnavailable("OpenAI model {$model} is not available.");
        }

        if ($response->failed()) {
            return null;
        }

        $text = $response->json('choices.0.message.content');

        if (! is_string($text)) {
            return null;
        }

        $decoded = ClassifierPrompt::decode($text);

        return $decoded === null ? null : ContentAssessment::fromModelOutput($decoded, $this->name(), $model);
    }
}
