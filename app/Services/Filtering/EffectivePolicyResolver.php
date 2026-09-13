<?php

namespace App\Services\Filtering;

use App\Enums\EnforcementAction;
use App\Enums\Severity;
use App\Models\BlockedDomain;
use App\Models\BlocklistSource;
use App\Models\ContentCategory;
use App\Models\ExceptionRequest;
use App\Models\FilteringPolicy;
use App\Models\Institution;
use App\Models\PolicyRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The single place a school's effective filtering policy is decided.
 *
 * Precedence, narrowest wins:
 *
 *   1. the learner group's policy, where one is in force for the group asked about;
 *   2. the institution's own policy;
 *   3. the county baseline.
 *
 * Everything that enforces — the client policy compilers and {@see DomainAdvisor}
 * — resolves through this class, so a portal assessment and a device cannot
 * disagree about whether a domain is blocked.
 */
class EffectivePolicyResolver
{
    /**
     * Resolve the complete effective policy for a school.
     *
     * The returned domain set is complete: truncating it for a client with a
     * rule ceiling is a delivery decision, taken by that client's compiler.
     */
    public function resolve(Institution $institution, ?int $learnerGroupId = null): EffectivePolicy
    {
        $policies = $this->governingPolicies($institution->id, $learnerGroupId);
        $actions = $this->categoryActions($policies);
        $sources = $this->enforceableSources($actions);

        $allowed = $this->normaliseAll([
            ...config('filtering.curriculum_allowlist', []),
            ...$this->allowedDomainsFor($institution->id),
        ]);

        $blocked = $this->blockedDomainsFor($sources->pluck('id')->all(), $allowed);
        $narrowest = $policies->last();

        return new EffectivePolicy(
            institutionId: $institution->id,
            learnerGroupId: $learnerGroupId,
            revision: $this->revisionFor($institution->id, $policies),
            policyVersion: $narrowest?->version,
            policyName: $narrowest?->name ?? 'Standard K-12 Safeguarding Policy',
            blockedDomains: $blocked,
            allowedDomains: $allowed,
            categories: $this->describeCategories($actions, $sources),
            contentHash: hash('sha256', implode("\n", $blocked)."\0".implode("\n", $allowed)),
            compiledAt: Carbon::now(),
        );
    }

    /**
     * The action one category carries for a school, using the same precedence
     * the client policies are compiled with.
     */
    public function actionForCategory(?ContentCategory $category, ?int $institutionId, ?int $learnerGroupId = null): EnforcementAction
    {
        return $this->decisionForCategory($category, $institutionId, $learnerGroupId)['action'];
    }

    /**
     * The winning rule for one category, so an assessment can name the rule it
     * applied rather than only its outcome.
     *
     * @return array{action: EnforcementAction, severity: ?Severity, policy_rule_id: ?int}
     */
    public function decisionForCategory(?ContentCategory $category, ?int $institutionId, ?int $learnerGroupId = null): array
    {
        $fallback = [
            'action' => EnforcementAction::Block,
            'severity' => $category?->default_severity,
            'policy_rule_id' => null,
        ];

        if ($category === null) {
            return $fallback;
        }

        $actions = $this->categoryActions($this->governingPolicies($institutionId, $learnerGroupId));
        $rule = $actions[$category->id]['rule'] ?? null;

        if ($rule === null) {
            return $fallback;
        }

        return [
            'action' => $rule->action,
            'severity' => $rule->severity ?? $category->default_severity,
            'policy_rule_id' => $rule->id,
        ];
    }

    /**
     * Whether a school currently allows a domain outright, through the
     * curriculum allowlist or an approved, unexpired exception. Parent domains
     * count: allowing example.com allows lessons.example.com.
     */
    public function isAllowed(string $domain, ?int $institutionId): bool
    {
        $allowed = $this->normaliseAll([
            ...config('filtering.curriculum_allowlist', []),
            ...($institutionId === null ? [] : $this->allowedDomainsFor($institutionId)),
        ]);

        return array_intersect($this->domainAndParents($domain), $allowed) !== [];
    }

    /**
     * Approved, unexpired exception domains for a school.
     *
     * @return list<string>
     */
    public function allowedDomainsFor(int $institutionId): array
    {
        return ExceptionRequest::query()
            ->withoutGlobalScopes()
            ->where('institution_id', $institutionId)
            ->where('status', 'approved')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('domain')
            ->all();
    }

    /**
     * A domain and every registrable parent of it, so a rule on example.com is
     * matched by ads.example.com.
     *
     * @return list<string>
     */
    public function domainAndParents(string $domain): array
    {
        $labels = explode('.', $this->normalise($domain));
        $candidates = [];

        for ($index = 0; $index < count($labels) - 1; $index++) {
            $candidates[] = implode('.', array_slice($labels, $index));
        }

        return $candidates;
    }

    /**
     * The active, in-force policies that govern a school, county baseline first
     * and the narrowest scope last.
     *
     * @return Collection<int, FilteringPolicy>
     */
    private function governingPolicies(?int $institutionId, ?int $learnerGroupId): Collection
    {
        $scopes = [
            fn ($query) => $query->whereNull('institution_id')->whereNull('learner_group_id'),
        ];

        if ($institutionId !== null) {
            $scopes[] = fn ($query) => $query->where('institution_id', $institutionId)->whereNull('learner_group_id');
        }

        if ($institutionId !== null && $learnerGroupId !== null) {
            $scopes[] = fn ($query) => $query->where('institution_id', $institutionId)->where('learner_group_id', $learnerGroupId);
        }

        return collect($scopes)
            ->map(fn (callable $scope): ?FilteringPolicy => FilteringPolicy::query()
                ->withoutGlobalScopes()
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->tap($scope)
                ->with(['rules' => fn ($rules) => $rules->withoutGlobalScopes()->with('category')])
                ->latest('version')
                ->first())
            ->filter()
            ->values();
    }

    /**
     * Fold the governing policies into one action per category. Later policies
     * are narrower, so they overwrite earlier ones unless the wider rule is
     * locked — a locked county rule is exactly what a school may not relax.
     *
     * @param  Collection<int, FilteringPolicy>  $policies
     * @return array<int, array{action: EnforcementAction, category: ?ContentCategory, rule: PolicyRule, locked: bool}>
     */
    private function categoryActions(Collection $policies): array
    {
        $actions = [];

        foreach ($policies as $policy) {
            foreach ($policy->rules as $rule) {
                /** @var PolicyRule $rule */
                $existing = $actions[$rule->content_category_id] ?? null;

                if ($existing !== null && $existing['locked']) {
                    continue;
                }

                $actions[$rule->content_category_id] = [
                    'action' => $rule->action,
                    'category' => $rule->category,
                    'rule' => $rule,
                    'locked' => $rule->is_locked,
                ];
            }
        }

        return $actions;
    }

    /**
     * Enabled sources whose category resolves to a block. A source with no
     * category keeps the safe default; a source whose category is allowed,
     * warned or restricted contributes no domain rules, because neither client
     * can enforce those actions at the domain layer.
     *
     * @param  array<int, array{action: EnforcementAction, category: ?ContentCategory, rule: PolicyRule, locked: bool}>  $actions
     * @return Collection<int, BlocklistSource>
     */
    private function enforceableSources(array $actions): Collection
    {
        return BlocklistSource::query()
            ->enabled()
            ->with('category')
            ->get()
            ->filter(function (BlocklistSource $source) use ($actions): bool {
                if ($source->content_category_id === null) {
                    return true;
                }

                $action = $actions[$source->content_category_id]['action'] ?? EnforcementAction::Block;

                return $action === EnforcementAction::Block;
            })
            ->values();
    }

    /**
     * Normalised, deduplicated, sorted domains for the given sources, less
     * anything the school allows. Deduplication happens before any capacity
     * decision, so duplicates across sources never consume a client's budget.
     *
     * @param  list<int>  $sourceIds
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function blockedDomainsFor(array $sourceIds, array $allowed): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $allowedIndex = array_flip($allowed);

        // Sorted after normalisation, not in SQL: the database collation orders
        // `SHARED.example` and `shared.example` differently, and an unstable
        // order would move the content hash without the policy changing.
        return BlockedDomain::query()
            ->whereIn('blocklist_source_id', $sourceIds)
            ->pluck('domain')
            ->map(fn (string $domain): string => $this->normalise($domain))
            ->filter()
            ->unique()
            ->reject(fn (string $domain): bool => isset($allowedIndex[$domain]))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * A revision that advances whenever the effective policy changes, including
     * when an approved exception simply reaches its expiry and nothing was
     * written. Clients compare it to know a cached policy is stale.
     *
     * @param  Collection<int, FilteringPolicy>  $policies
     */
    private function revisionFor(int $institutionId, Collection $policies): int
    {
        $exceptions = fn () => ExceptionRequest::query()
            ->withoutGlobalScopes()
            ->where('institution_id', $institutionId);

        $stamps = [
            $policies->max('updated_at'),
            PolicyRule::query()->withoutGlobalScopes()
                ->whereIn('filtering_policy_id', $policies->pluck('id'))
                ->max('updated_at'),
            BlocklistSource::query()->max('updated_at'),
            $exceptions()->max('updated_at'),
            $exceptions()->where('status', 'approved')->where('expires_at', '<=', now())->max('expires_at'),
        ];

        $latest = collect($stamps)
            ->filter()
            ->map(fn ($stamp): int => Carbon::parse($stamp)->getTimestamp())
            ->max();

        return $latest ?? 1;
    }

    /**
     * @param  array<int, array{action: EnforcementAction, category: ?ContentCategory, rule: PolicyRule, locked: bool}>  $actions
     * @param  Collection<int, BlocklistSource>  $sources
     * @return list<array{name: string, slug: string, action: string, enforced_by_domain_rules: bool}>
     */
    private function describeCategories(array $actions, Collection $sources): array
    {
        $enforced = $sources->pluck('content_category_id')->filter()->unique()->flip();

        return collect($actions)
            ->filter(fn (array $entry): bool => $entry['category'] !== null)
            ->map(fn (array $entry, int $categoryId): array => [
                'name' => $entry['category']->name,
                'slug' => $entry['category']->slug,
                'action' => $entry['action']->value,
                'enforced_by_domain_rules' => $enforced->has($categoryId),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @param  iterable<string>  $domains
     * @return list<string>
     */
    private function normaliseAll(iterable $domains): array
    {
        return collect($domains)
            ->map(fn (string $domain): string => $this->normalise($domain))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function normalise(string $domain): string
    {
        return trim(mb_strtolower(trim($domain)), '.');
    }
}
