<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Routes a classification request to whichever provider this deployment can
 * actually reach.
 *
 * Selection is automatic: with AI_PROVIDER=auto the first configured provider in
 * the configured priority order is used, and if it fails repeatedly the next one
 * is tried. Within a provider the model ids are tried in order, so the
 * retirement of a single model degrades the layer instead of disabling it.
 *
 * The classifier fails open by design. `classify()` returns null whenever the
 * layer is unavailable, slow, or answers with something unusable, and callers
 * must fall back to the deterministic filtering rules. It is an assistant to
 * policy, never a policy authority.
 */
class ContentClassifier
{
    /** @param list<ClassifierProvider> $providers */
    public function __construct(private readonly array $providers) {}

    /** @return list<ClassifierProvider> */
    public function available(): array
    {
        return array_values(array_filter($this->providers, fn (ClassifierProvider $provider) => $provider->isConfigured()));
    }

    public function isEnabled(): bool
    {
        return $this->available() !== [];
    }

    /** The provider a request would use right now, or null when none can be reached. */
    public function activeProvider(): ?ClassifierProvider
    {
        foreach ($this->available() as $provider) {
            if (! $this->breakerOpen($provider)) {
                return $provider;
            }
        }

        return null;
    }

    public function classify(string $url, string $query = ''): ?ContentAssessment
    {
        $url = trim($url);
        $query = trim($query);

        if (mb_strlen($url) < 5 && mb_strlen($query) < 5) {
            return null;
        }

        $cacheKey = 'ai:classification:'.hash('sha256', $url."\0".$query);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return ContentAssessment::fromModelOutput(
                $cached + ['riskSeverity' => $cached['severity'] ?? null],
                $cached['provider'] ?? 'cache',
                $cached['model'] ?? 'cache',
            );
        }

        foreach ($this->available() as $provider) {
            if ($this->breakerOpen($provider)) {
                continue;
            }

            $assessment = $this->askProvider($provider, $url, $query);

            if ($assessment !== null) {
                $this->resetBreaker($provider);
                Cache::put($cacheKey, $assessment->toArray() + ['riskScore' => $assessment->riskScore], config('ai.cache_ttl', 900));

                return $assessment;
            }

            $this->recordFailure($provider);
        }

        return null;
    }

    private function askProvider(ClassifierProvider $provider, string $url, string $query): ?ContentAssessment
    {
        foreach ($provider->models() as $model) {
            if ($this->modelRetired($provider, $model)) {
                continue;
            }

            try {
                $assessment = $provider->classify($model, $url, $query);

                if ($assessment !== null) {
                    return $assessment;
                }
            } catch (ModelUnavailable $exception) {
                // Burn the id rather than retrying it on every request, and say
                // so out loud: a dead model otherwise degrades silently for months.
                Log::warning('SAFERNET classifier model retired.', [
                    'provider' => $provider->name(),
                    'model' => $model,
                    'reason' => $exception->getMessage(),
                ]);
                Cache::put($this->retiredKey($provider, $model), true, now()->addDay());
            } catch (Throwable $exception) {
                Log::warning('SAFERNET classifier request failed.', [
                    'provider' => $provider->name(),
                    'model' => $model,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        return null;
    }

    private function breakerKey(ClassifierProvider $provider): string
    {
        return "ai:breaker:{$provider->name()}";
    }

    private function retiredKey(ClassifierProvider $provider, string $model): string
    {
        return "ai:retired:{$provider->name()}:{$model}";
    }

    private function modelRetired(ClassifierProvider $provider, string $model): bool
    {
        return (bool) Cache::get($this->retiredKey($provider, $model), false);
    }

    private function breakerOpen(ClassifierProvider $provider): bool
    {
        return (int) Cache::get($this->breakerKey($provider), 0) >= (int) config('ai.breaker.threshold', 5);
    }

    private function recordFailure(ClassifierProvider $provider): void
    {
        $failures = (int) Cache::get($this->breakerKey($provider), 0) + 1;
        Cache::put($this->breakerKey($provider), $failures, (int) config('ai.breaker.cooldown', 60));
    }

    private function resetBreaker(ClassifierProvider $provider): void
    {
        Cache::forget($this->breakerKey($provider));
    }
}
