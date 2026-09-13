<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Client delivery capacity
    |--------------------------------------------------------------------------
    |
    | The effective policy is one thing; how much of it a given client can hold
    | is another. A Chromium extension has a ceiling on dynamic rules, so its
    | delivery is truncated deliberately and the truncation is reported. The
    | Windows agent keeps a hash set and has no comparable ceiling, so it is
    | given the complete policy unless an operator caps it explicitly.
    |
    | Measured on Chrome 152: 7,957 dynamic rules installed cleanly, no error.
    | The previous 4,500 was therefore a self-imposed cap, not a browser one,
    | and it left 43% of a 7,946-domain policy silently unenforced. 20,000 is
    | verified-safe headroom, not a measured ceiling.
    |
    | Raising it does not solve the problem at county scale. The configured
    | upstream sources total roughly 590,000 domains, so even at Chrome's
    | documented ceiling the browser holds about 5% of the policy. The browser
    | is defence-in-depth; the endpoint agent, which takes the complete set, is
    | the enforcement layer. Until the delivered slice is ordered by risk, which
    | slice gets enforced is decided alphabetically — see ROLLOUT-READINESS.md.
    |
    */

    'browser_rule_limit' => (int) env('FILTERING_BROWSER_RULE_LIMIT', 20000),

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
