<?php

namespace App\Services\Filtering;

use App\Enums\EnforcementAction;
use App\Enums\Severity;
use App\Models\BlockedDomain;
use App\Services\Ai\ContentClassifier;

/**
 * Advises a gateway or endpoint agent about one request.
 *
 * Order matters and is deliberate:
 *
 *   1. the school's allowlist — the curriculum baseline and approved,
 *      unexpired exceptions, which is what an approval in the portal means;
 *   2. the county blocklists, which are deterministic, auditable and the same
 *      for every school;
 *   3. the school's own filtering policy for the category that match belongs to;
 *   4. only where all are silent, the classifier — and only as advice.
 *
 * A model never softens a county-mandated block: a blocklist match is answered
 * from policy, and the classifier is consulted for unknown domains alone.
 *
 * Category actions and exceptions are resolved by {@see EffectivePolicyResolver},
 * the same service that compiles the agent and extension policies, so an
 * assessment here and a device in a laboratory cannot reach different answers.
 */
class DomainAdvisor
{
    public function __construct(
        private readonly ContentClassifier $classifier,
        private readonly EffectivePolicyResolver $resolver,
    ) {}

    /**
     * @return array{
     *     domain: string,
     *     source: string,
     *     action: string,
     *     severity: string,
     *     category: ?string,
     *     content_category_id: ?int,
     *     policy_rule_id: ?int,
     *     rationale: string,
     *     provider: ?string,
     *     model: ?string
     * }
     */
    public function assess(string $url, ?int $institutionId = null, string $query = '', ?int $learnerGroupId = null): array
    {
        $domain = $this->domainOf($url);

        if ($domain !== null) {
            if ($this->resolver->isAllowed($domain, $institutionId)) {
                return [
                    'domain' => $domain,
                    'source' => 'allowlist',
                    'action' => EnforcementAction::Allow->value,
                    'severity' => Severity::Low->value,
                    'category' => null,
                    'content_category_id' => null,
                    'policy_rule_id' => null,
                    'rationale' => 'On the curriculum allowlist or covered by an approved, unexpired exception.',
                    'provider' => null,
                    'model' => null,
                ];
            }

            $match = $this->blocklistMatch($domain);

            if ($match !== null) {
                return $this->fromBlocklist($domain, $match, $institutionId, $learnerGroupId);
            }
        }

        $assessment = $this->classifier->classify($url, $query);

        if ($assessment === null) {
            return [
                'domain' => $domain ?? '',
                'source' => 'unknown',
                'action' => EnforcementAction::Allow->value,
                'severity' => Severity::Low->value,
                'category' => null,
                'content_category_id' => null,
                'policy_rule_id' => null,
                'rationale' => 'No county blocklist match, and no classifier was available. The device applies its local policy.',
                'provider' => null,
                'model' => null,
            ];
        }

        return [
            'domain' => $domain ?? '',
            'source' => 'classifier',
            'action' => $assessment->action->value,
            'severity' => $assessment->severity->value,
            'category' => $assessment->category,
            'content_category_id' => null,
            'policy_rule_id' => null,
            'rationale' => $assessment->rationale,
            'provider' => $assessment->provider,
            'model' => $assessment->model,
        ];
    }

    /** Registrable-domain walk: a block on example.com covers ads.example.com. */
    public function blocklistMatch(string $domain): ?BlockedDomain
    {
        return BlockedDomain::query()
            ->with('source.category')
            ->whereIn('domain', $this->resolver->domainAndParents($domain))
            ->join('blocklist_sources', 'blocklist_sources.id', '=', 'blocked_domains.blocklist_source_id')
            ->where('blocklist_sources.is_enabled', true)
            ->select('blocked_domains.*')
            ->first();
    }

    public function domainOf(string $url): ?string
    {
        $host = parse_url(str_contains($url, '://') ? $url : "https://{$url}", PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower(ltrim($host, '.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function fromBlocklist(string $domain, BlockedDomain $match, ?int $institutionId, ?int $learnerGroupId): array
    {
        $category = $match->source->category;
        $decision = $this->resolver->decisionForCategory($category, $institutionId, $learnerGroupId);

        return [
            'domain' => $domain,
            'source' => 'blocklist',
            'action' => $decision['action']->value,
            'severity' => ($decision['severity'] ?? Severity::High)->value,
            'category' => $category?->name,
            'content_category_id' => $category?->id,
            'policy_rule_id' => $decision['policy_rule_id'],
            'rationale' => sprintf('Listed by %s as %s.', $match->source->name, $category?->name ?? 'a blocked category'),
            'provider' => null,
            'model' => null,
        ];
    }
}
