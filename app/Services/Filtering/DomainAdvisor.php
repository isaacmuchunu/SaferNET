<?php

namespace App\Services\Filtering;

use App\Enums\EnforcementAction;
use App\Enums\Severity;
use App\Models\BlockedDomain;
use App\Models\ContentCategory;
use App\Models\PolicyRule;
use App\Services\Ai\ContentClassifier;

/**
 * Advises a gateway or endpoint agent about one request.
 *
 * Order matters and is deliberate:
 *
 *   1. the county blocklists, which are deterministic, auditable and the same
 *      for every school;
 *   2. the school's own filtering policy for the category that match belongs to;
 *   3. only where both are silent, the classifier — and only as advice.
 *
 * A model never softens a county-mandated block: a blocklist match is answered
 * from policy, and the classifier is consulted for unknown domains alone.
 */
class DomainAdvisor
{
    public function __construct(private readonly ContentClassifier $classifier) {}

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
    public function assess(string $url, ?int $institutionId = null, string $query = ''): array
    {
        $domain = $this->domainOf($url);

        if ($domain !== null) {
            $match = $this->blocklistMatch($domain);

            if ($match !== null) {
                return $this->fromBlocklist($domain, $match, $institutionId);
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
        $labels = explode('.', $domain);
        $candidates = [];

        for ($index = 0; $index < count($labels) - 1; $index++) {
            $candidates[] = implode('.', array_slice($labels, $index));
        }

        return BlockedDomain::query()
            ->with('source.category')
            ->whereIn('domain', $candidates)
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
    private function fromBlocklist(string $domain, BlockedDomain $match, ?int $institutionId): array
    {
        $category = $match->source->category;
        $rule = $category === null ? null : $this->ruleFor($category, $institutionId);

        $action = $rule?->action ?? EnforcementAction::Block;
        $severity = $rule?->severity ?? ($category?->default_severity ?? Severity::High);

        return [
            'domain' => $domain,
            'source' => 'blocklist',
            'action' => $action instanceof EnforcementAction ? $action->value : (string) $action,
            'severity' => $severity instanceof Severity ? $severity->value : (string) $severity,
            'category' => $category?->name,
            'content_category_id' => $category?->id,
            'policy_rule_id' => $rule?->id,
            'rationale' => sprintf('Listed by %s as %s.', $match->source->name, $category?->name ?? 'a blocked category'),
            'provider' => null,
            'model' => null,
        ];
    }

    /**
     * The narrowest active rule that governs this category: the school's own
     * policy when it has one, otherwise the county baseline.
     */
    private function ruleFor(ContentCategory $category, ?int $institutionId): ?PolicyRule
    {
        return PolicyRule::query()
            ->where('content_category_id', $category->id)
            ->whereHas('policy', fn ($policies) => $policies
                ->where('status', 'active')
                ->where(fn ($scoped) => $scoped
                    ->whereNull('institution_id')
                    ->when($institutionId, fn ($query, $id) => $query->orWhere('institution_id', $id))))
            ->with('policy')
            ->get()
            ->sortByDesc(fn (PolicyRule $rule) => $rule->policy->institution_id === null ? 0 : 1)
            ->first();
    }
}
