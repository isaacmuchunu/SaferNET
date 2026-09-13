<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\ClassifierPrompt;
use App\Services\Ai\ClassifierProvider;
use App\Services\Ai\ContentAssessment;
use App\Services\Ai\ModelUnavailable;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements ClassifierProvider
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
        return 'gemini';
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
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->post("{$this->endpoint}/{$model}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => ClassifierPrompt::system()]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => ClassifierPrompt::user($url, $query)]]]],
                'generationConfig' => [
                    'temperature' => 0,
                    'maxOutputTokens' => 300,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if ($response->status() === 404 || str_contains(strtolower($response->body()), 'no longer available')) {
            throw new ModelUnavailable("Gemini model {$model} is not available: ".$response->status());
        }

        if ($response->failed()) {
            return null;
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text)) {
            return null;
        }

        $decoded = ClassifierPrompt::decode($text);

        return $decoded === null ? null : ContentAssessment::fromModelOutput($decoded, $this->name(), $model);
    }
}
