<?php

namespace App\Services\Ai;

interface ClassifierProvider
{
    public function name(): string;

    /** True when this deployment has the credentials this provider needs. */
    public function isConfigured(): bool;

    /** @return list<string> The model ids to try, in order. */
    public function models(): array;

    /**
     * Ask one model to classify the request.
     *
     * @throws ModelUnavailable when the model id itself is dead, so the caller
     *                          can advance to the next id rather than retrying.
     */
    public function classify(string $model, string $url, string $query): ?ContentAssessment;
}
