<?php

namespace App\Services\Filtering;

/**
 * Compiles an {@see EffectivePolicy} into the Windows agent's delivery.
 *
 * The agent keeps a hash set on disk and has no rule ceiling, so it receives
 * the complete policy. An operator may still cap it through
 * `filtering.agent_domain_limit`, and the cap is then reported rather than
 * applied silently.
 */
class AgentPolicyCompiler
{
    /**
     * @return array{
     *     blocked_domains: list<string>,
     *     allowed_domains: list<string>,
     *     total_domains: int,
     *     installed_domains: int,
     *     complete: bool
     * }
     */
    public function compile(EffectivePolicy $policy): array
    {
        $limit = config('filtering.agent_domain_limit');

        $installed = $limit === null
            ? $policy->blockedDomains
            : array_slice($policy->blockedDomains, 0, (int) $limit);

        return [
            'blocked_domains' => $installed,
            'allowed_domains' => $policy->allowedDomains,
            'total_domains' => $policy->totalBlockedDomains(),
            'installed_domains' => count($installed),
            'complete' => count($installed) === $policy->totalBlockedDomains(),
        ];
    }
}
