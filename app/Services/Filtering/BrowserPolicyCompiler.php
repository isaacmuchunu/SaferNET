<?php

namespace App\Services\Filtering;

/**
 * Compiles an {@see EffectivePolicy} into a Chromium declarativeNetRequest
 * delivery.
 *
 * The browser has a hard ceiling on dynamic rules, so this is the one place a
 * policy is allowed to be delivered incompletely — and it says so. The caller
 * receives `complete`, `installed_domains` and `total_domains` alongside the
 * rules, so a truncated browser ruleset is never mistaken for the school's
 * whole policy.
 */
class BrowserPolicyCompiler
{
    private const SAFE_SEARCH_RULE_ID = 1;

    private const STRICT_SEARCH_RULE_ID = 2;

    private const YOUTUBE_RULE_ID = 3;

    private const ALLOW_RULE_BASE = 100;

    private const BLOCK_RULE_BASE = 100_000;

    /**
     * Allow rules outrank block rules so an approved exception survives a
     * parent-domain entry on an upstream list.
     */
    private const ALLOW_PRIORITY = 100;

    private const BLOCK_PRIORITY = 1;

    /**
     * @return array{
     *     dnr_rules: list<array<string, mixed>>,
     *     blocked_domains: list<string>,
     *     allowed_domains: list<string>,
     *     total_domains: int,
     *     installed_domains: int,
     *     complete: bool
     * }
     */
    public function compile(EffectivePolicy $policy): array
    {
        $rules = $this->searchAndVideoRules();

        foreach ($policy->allowedDomains as $index => $domain) {
            $rules[] = [
                'id' => self::ALLOW_RULE_BASE + $index,
                'priority' => self::ALLOW_PRIORITY,
                'action' => ['type' => 'allow'],
                'condition' => [
                    'urlFilter' => "||{$domain}^",
                    'resourceTypes' => ['main_frame', 'sub_frame', 'xmlhttprequest'],
                ],
            ];
        }

        $budget = max(0, (int) config('filtering.browser_rule_limit', 4500) - count($rules));
        $installed = array_slice($policy->blockedDomains, 0, $budget);

        foreach ($installed as $index => $domain) {
            $rules[] = [
                'id' => self::BLOCK_RULE_BASE + $index,
                'priority' => self::BLOCK_PRIORITY,
                'action' => ['type' => 'block'],
                'condition' => [
                    'urlFilter' => "||{$domain}^",
                    'resourceTypes' => ['main_frame', 'sub_frame', 'xmlhttprequest'],
                ],
            ];
        }

        return [
            'dnr_rules' => $rules,
            'blocked_domains' => $installed,
            'allowed_domains' => $policy->allowedDomains,
            'total_domains' => $policy->totalBlockedDomains(),
            'installed_domains' => count($installed),
            'complete' => count($installed) === $policy->totalBlockedDomains(),
        ];
    }

    /**
     * Safe search and restricted video, which are transforms rather than domain
     * rules and so never compete for the domain budget.
     *
     * @return list<array<string, mixed>>
     */
    private function searchAndVideoRules(): array
    {
        return [
            [
                'id' => self::SAFE_SEARCH_RULE_ID,
                'priority' => 10,
                'action' => [
                    'type' => 'redirect',
                    'redirect' => [
                        'transform' => [
                            'queryTransform' => [
                                'addOrReplaceParams' => [['key' => 'safe', 'value' => 'active']],
                            ],
                        ],
                    ],
                ],
                'condition' => ['urlFilter' => '||google.com/search', 'resourceTypes' => ['main_frame']],
            ],
            [
                'id' => self::STRICT_SEARCH_RULE_ID,
                'priority' => 10,
                'action' => [
                    'type' => 'redirect',
                    'redirect' => [
                        'transform' => [
                            'queryTransform' => [
                                'addOrReplaceParams' => [['key' => 'adlt', 'value' => 'strict']],
                            ],
                        ],
                    ],
                ],
                'condition' => ['urlFilter' => '||bing.com/search', 'resourceTypes' => ['main_frame']],
            ],
            [
                'id' => self::YOUTUBE_RULE_ID,
                'priority' => 10,
                'action' => [
                    'type' => 'modifyHeaders',
                    'requestHeaders' => [[
                        'header' => 'YouTube-Restrict',
                        'operation' => 'set',
                        'value' => 'Strict',
                    ]],
                ],
                'condition' => [
                    'requestDomains' => ['youtube.com', 'www.youtube.com', 'm.youtube.com'],
                    'resourceTypes' => ['main_frame', 'sub_frame', 'xmlhttprequest'],
                ],
            ],
        ];
    }
}
