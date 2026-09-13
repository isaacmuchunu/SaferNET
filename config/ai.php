<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content classification
    |--------------------------------------------------------------------------
    |
    | The classifier is an assistant to the filtering policy, never the policy
    | itself. It runs only where the deterministic rules have nothing to say
    | about a domain, and when it is unavailable, slow or returns something
    | unusable the decision falls back to those rules. A county-mandated block is
    | never softened by a model.
    |
    | "auto" selects the first provider that is actually configured, in the order
    | below. Name a provider explicitly to pin it.
    |
    */

    'provider' => env('AI_PROVIDER', 'auto'),

    'priority' => ['gemini', 'anthropic', 'openai'],

    'timeout' => (int) env('AI_TIMEOUT', 8),

    /*
    | Schools re-request the same handful of domains all day, so a short cache
    | keeps both latency and spend down.
    */
    'cache_ttl' => (int) env('AI_CACHE_TTL', 900),

    /*
    | After this many consecutive failures the classifier stops being called for
    | the cooldown, so a misconfigured key or a provider outage costs one timeout
    | rather than one per request.
    */
    'breaker' => [
        'threshold' => (int) env('AI_BREAKER_THRESHOLD', 5),
        'cooldown' => (int) env('AI_BREAKER_COOLDOWN', 60),
    ],

    'providers' => [

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'endpoint' => env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models'),
            // A list rather than one id: when Google retires a model the layer
            // degrades to the next instead of failing on every request.
            'models' => array_values(array_filter(array_map('trim', explode(',', (string) env(
                'GEMINI_MODELS',
                'gemini-flash-latest,gemini-2.0-flash',
            ))))),
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'endpoint' => env('ANTHROPIC_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
            'models' => array_values(array_filter(array_map('trim', explode(',', (string) env(
                'ANTHROPIC_MODELS',
                'claude-haiku-4-5-20251001',
            ))))),
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'endpoint' => env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
            'models' => array_values(array_filter(array_map('trim', explode(',', (string) env(
                'OPENAI_MODELS',
                'gpt-4o-mini',
            ))))),
        ],

    ],

];
