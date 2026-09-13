<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upstream blocklist fetching
    |--------------------------------------------------------------------------
    |
    | These lists are fetched server-side, so the fetch itself is an SSRF vector
    | unless it is contained: https only, a host allowlist, no redirects (a
    | redirect is a second, unvalidated request), a hard byte cap enforced while
    | streaming, and a refusal to connect to private or link-local addresses.
    |
    */

    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BLOCKLIST_ALLOWED_HOSTS', 'raw.githubusercontent.com')),
    ))),

    'max_bytes' => (int) env('BLOCKLIST_MAX_BYTES', 8 * 1024 * 1024),
    'timeout' => (int) env('BLOCKLIST_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    |
    | Real, publicly published lists. `approx_domains` is advisory and dated: the
    | authoritative count is written to blocklist_sources.domains_count by a
    | synchronisation that actually completed. `category` maps onto the content
    | category vocabulary the filtering policies are written against.
    |
    */

    'verified_on' => '2026-09-06',

    'sources' => [
        [
            'slug' => 'stevenblack-porn',
            'name' => 'StevenBlack — Adult & Pornography',
            'url' => 'https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/porn/hosts',
            'category' => 'pornography',
            'description' => 'Consolidated adult-content hosts list — the core obscenity control for a learner network.',
            'provenance' => 'github.com/StevenBlack/hosts (MIT)',
            'enabled' => true,
            'approx_domains' => 156_119,
        ],
        [
            'slug' => 'ut1-redirector',
            'name' => 'UT1 — Web Proxies & Redirectors',
            'url' => 'https://raw.githubusercontent.com/olbat/ut1-blacklists/master/blacklists/redirector/domains',
            'category' => 'proxy-anonymizers',
            'description' => 'Web-based unblocking proxies — the category learners actually use to get around a school filter.',
            'provenance' => 'github.com/olbat/ut1-blacklists — Université Toulouse 1 Capitole',
            'enabled' => true,
            'approx_domains' => 132_320,
        ],
        [
            'slug' => 'ut1-vpn',
            'name' => 'UT1 — VPN Providers',
            'url' => 'https://raw.githubusercontent.com/olbat/ut1-blacklists/master/blacklists/vpn/domains',
            'category' => 'proxy-anonymizers',
            'description' => 'Commercial and free VPN provider entry points.',
            'provenance' => 'github.com/olbat/ut1-blacklists — Université Toulouse 1 Capitole',
            'enabled' => true,
            'approx_domains' => 6_039,
        ],
        [
            'slug' => 'ut1-doh',
            'name' => 'UT1 — DNS-over-HTTPS Resolvers',
            'url' => 'https://raw.githubusercontent.com/olbat/ut1-blacklists/master/blacklists/doh/domains',
            'category' => 'proxy-anonymizers',
            'description' => 'Public DoH resolvers. A device reaching one resolves names outside the school gateway, so DNS filtering stops applying.',
            'provenance' => 'github.com/olbat/ut1-blacklists — Université Toulouse 1 Capitole',
            'enabled' => true,
            'approx_domains' => 3_014,
        ],
        [
            'slug' => 'ut1-gambling',
            'name' => 'UT1 — Gambling & Betting',
            'url' => 'https://raw.githubusercontent.com/olbat/ut1-blacklists/master/blacklists/gambling/domains',
            'category' => 'gambling',
            'description' => 'Betting and casino domains. Gambling is age-restricted under the Betting, Lotteries and Gaming Act.',
            'provenance' => 'github.com/olbat/ut1-blacklists — Université Toulouse 1 Capitole',
            'enabled' => true,
            'approx_domains' => 32_247,
        ],
        [
            'slug' => 'ut1-phishing',
            'name' => 'UT1 — Phishing & Credential Theft',
            'url' => 'https://raw.githubusercontent.com/olbat/ut1-blacklists/master/blacklists/phishing/domains',
            'category' => 'malware-phishing',
            'description' => 'Phishing hosts. Protects learner and staff credentials on shared laboratory machines.',
            'provenance' => 'github.com/olbat/ut1-blacklists — Université Toulouse 1 Capitole',
            'enabled' => true,
            'approx_domains' => 250_556,
        ],
        [
            'slug' => 'blocklistproject-ransomware',
            'name' => 'BlocklistProject — Ransomware',
            'url' => 'https://raw.githubusercontent.com/blocklistproject/Lists/master/ransomware.txt',
            'category' => 'malware-phishing',
            'description' => 'Known ransomware command-and-control and payload hosts.',
            'provenance' => 'github.com/blocklistproject/Lists (Unlicense)',
            'enabled' => true,
            'approx_domains' => 1_800,
        ],
        [
            'slug' => 'blocklistproject-scam',
            'name' => 'BlocklistProject — Scams',
            'url' => 'https://raw.githubusercontent.com/blocklistproject/Lists/master/scam.txt',
            'category' => 'malware-phishing',
            'description' => 'Scam and fraudulent-offer hosts.',
            'provenance' => 'github.com/blocklistproject/Lists (Unlicense)',
            'enabled' => true,
            'approx_domains' => 8_527,
        ],
        [
            'slug' => 'ut1-social',
            'name' => 'UT1 — Social Networks',
            'url' => 'https://raw.githubusercontent.com/olbat/ut1-blacklists/master/blacklists/social_networks/domains',
            'category' => 'social-media',
            'description' => 'Social network domains. Off by default: most schools restrict social media by timetable rather than blocking it outright.',
            'provenance' => 'github.com/olbat/ut1-blacklists — Université Toulouse 1 Capitole',
            'enabled' => false,
            'approx_domains' => 4_500,
        ],
    ],

];
