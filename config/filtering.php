<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Client delivery capacity
    |--------------------------------------------------------------------------
    |
    | The effective policy is one thing; how much of it a given client can hold
    | is another. A Chromium extension has a hard ceiling on dynamic rules, so
    | its delivery is truncated deliberately and the truncation is reported. The
    | Windows agent keeps a hash set and has no comparable ceiling, so it is
    | given the complete policy unless an operator caps it explicitly.
    |
    */

    'browser_rule_limit' => (int) env('FILTERING_BROWSER_RULE_LIMIT', 4500),

    'agent_domain_limit' => env('FILTERING_AGENT_DOMAIN_LIMIT') === null
        ? null
        : (int) env('FILTERING_AGENT_DOMAIN_LIMIT'),

    /*
    |--------------------------------------------------------------------------
    | Curriculum allowlist
    |--------------------------------------------------------------------------
    |
    | Domains the county never blocks. They override a blocklist match the same
    | way an approved exception does, so a parent-domain entry on an upstream
    | list cannot take a ministry or examination service off the air.
    |
    */

    'curriculum_allowlist' => [
        'education.go.ke',
        'kicd.ac.ke',
        'nemis.education.go.ke',
        'khanacademy.org',
        'classroom.google.com',
        'wikipedia.org',
    ],

];
