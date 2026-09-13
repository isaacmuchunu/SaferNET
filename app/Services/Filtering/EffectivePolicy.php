<?php

namespace App\Services\Filtering;

use App\Enums\EnforcementAction;
use Illuminate\Support\Carbon;

/**
 * One school's resolved filtering policy, before any client-specific delivery
 * decision is taken.
 *
 * Everything a client needs to enforce is already applied here: disabled
 * sources are gone, category actions are resolved through the county →
 * institution → learner-group chain, and approved exceptions and the
 * curriculum allowlist have been subtracted. A client that installs the whole
 * of `blockedDomains` and honours `allowedDomains` enforces exactly what the
 * portal shows.
 */
final readonly class EffectivePolicy
{
    /**
     * @param  list<string>  $blockedDomains
     * @param  list<string>  $allowedDomains
     * @param  list<array{name: string, slug: string, action: string, enforced_by_domain_rules: bool}>  $categories
     */
    public function __construct(
        public int $institutionId,
        public ?int $learnerGroupId,
        public int $revision,
        public ?int $policyVersion,
        public string $policyName,
        public array $blockedDomains,
        public array $allowedDomains,
        public array $categories,
        public string $contentHash,
        public Carbon $compiledAt,
    ) {}

    public function totalBlockedDomains(): int
    {
        return count($this->blockedDomains);
    }

    /**
     * Category names the policy blocks. Descriptive: a category only reaches a
     * client as domain rules when `enforced_by_domain_rules` is true.
     *
     * @return list<string>
     */
    public function blockedCategoryNames(): array
    {
        return array_values(array_map(
            fn (array $category): string => $category['name'],
            array_filter(
                $this->categories,
                fn (array $category): bool => $category['action'] === EnforcementAction::Block->value,
            ),
        ));
    }
}
