<?php

namespace Tests\Feature\Services;

use App\Models\BlockedDomain;
use App\Models\BlocklistSource;
use App\Models\ContentCategory;
use App\Models\ExceptionRequest;
use App\Models\FilteringPolicy;
use App\Models\Institution;
use App\Models\LearnerGroup;
use App\Models\PolicyRule;
use App\Services\Filtering\AgentPolicyCompiler;
use App\Services\Filtering\BrowserPolicyCompiler;
use App\Services\Filtering\DomainAdvisor;
use App\Services\Filtering\EffectivePolicyResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The resolver is the one place effective policy is decided, so these tests
 * pin the contract both clients and the portal assessment depend on.
 */
class EffectivePolicyResolverTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_domains_are_deduplicated_across_sources_before_any_client_capacity_applies(): void
    {
        $institution = Institution::factory()->create();
        $category = ContentCategory::factory()->create();

        $first = $this->source($category);
        $second = $this->source($category);

        foreach (['shared.example', 'only-first.example'] as $domain) {
            BlockedDomain::create(['blocklist_source_id' => $first->id, 'domain' => $domain]);
        }
        BlockedDomain::create(['blocklist_source_id' => $second->id, 'domain' => 'SHARED.example']);

        $policy = app(EffectivePolicyResolver::class)->resolve($institution);

        $this->assertSame(['only-first.example', 'shared.example'], $policy->blockedDomains);
    }

    public function test_the_agent_receives_every_domain_while_the_browser_reports_its_truncation(): void
    {
        config(['filtering.browser_rule_limit' => 12]);
        $institution = Institution::factory()->create();
        $source = $this->source(ContentCategory::factory()->create());

        foreach (range(1, 20) as $index) {
            BlockedDomain::create([
                'blocklist_source_id' => $source->id,
                'domain' => sprintf('domain-%02d.example', $index),
            ]);
        }

        $policy = app(EffectivePolicyResolver::class)->resolve($institution);

        $agent = app(AgentPolicyCompiler::class)->compile($policy);
        $this->assertSame(20, $agent['installed_domains']);
        $this->assertTrue($agent['complete']);
        $this->assertContains('domain-20.example', $agent['blocked_domains']);

        $browser = app(BrowserPolicyCompiler::class)->compile($policy);
        $this->assertFalse($browser['complete']);
        $this->assertSame(20, $browser['total_domains']);
        $this->assertLessThan(20, $browser['installed_domains']);
        $this->assertNotContains('domain-20.example', $browser['blocked_domains']);
        $this->assertLessThanOrEqual(12, count($browser['dnr_rules']));
    }

    public function test_a_disabled_source_stops_reaching_clients_and_the_advisor_agrees(): void
    {
        $institution = Institution::factory()->create();
        $source = $this->source(ContentCategory::factory()->create());
        BlockedDomain::create(['blocklist_source_id' => $source->id, 'domain' => 'gambling.example']);

        $resolver = app(EffectivePolicyResolver::class);
        $this->assertContains('gambling.example', $resolver->resolve($institution)->blockedDomains);

        $source->update(['is_enabled' => false]);

        $this->assertNotContains('gambling.example', $resolver->resolve($institution->fresh())->blockedDomains);
        $this->assertSame('unknown', app(DomainAdvisor::class)->assess('https://gambling.example/')['source']);
    }

    public function test_a_category_the_school_does_not_block_contributes_no_domain_rules(): void
    {
        $institution = Institution::factory()->create();
        $category = ContentCategory::factory()->create();
        $source = $this->source($category);
        BlockedDomain::create(['blocklist_source_id' => $source->id, 'domain' => 'social.example']);

        $countyPolicy = FilteringPolicy::factory()->create(['level' => 'county', 'status' => 'active']);
        PolicyRule::factory()->create([
            'filtering_policy_id' => $countyPolicy->id,
            'content_category_id' => $category->id,
            'action' => 'block',
        ]);

        $schoolPolicy = FilteringPolicy::factory()->create([
            'level' => 'institution',
            'institution_id' => $institution->id,
            'status' => 'active',
        ]);
        PolicyRule::factory()->create([
            'filtering_policy_id' => $schoolPolicy->id,
            'content_category_id' => $category->id,
            'action' => 'warn',
        ]);

        $policy = app(EffectivePolicyResolver::class)->resolve($institution);

        $this->assertNotContains('social.example', $policy->blockedDomains);
        $this->assertSame('warn', $policy->categories[0]['action']);
        $this->assertFalse($policy->categories[0]['enforced_by_domain_rules']);

        $assessment = app(DomainAdvisor::class)->assess('https://social.example/', $institution->id);
        $this->assertSame('warn', $assessment['action']);
    }

    public function test_a_locked_county_rule_cannot_be_relaxed_by_a_school(): void
    {
        $institution = Institution::factory()->create();
        $category = ContentCategory::factory()->create();
        $source = $this->source($category);
        BlockedDomain::create(['blocklist_source_id' => $source->id, 'domain' => 'porn.example']);

        $countyPolicy = FilteringPolicy::factory()->create(['level' => 'county', 'status' => 'active']);
        PolicyRule::factory()->create([
            'filtering_policy_id' => $countyPolicy->id,
            'content_category_id' => $category->id,
            'action' => 'block',
            'is_locked' => true,
        ]);

        $schoolPolicy = FilteringPolicy::factory()->create([
            'level' => 'institution',
            'institution_id' => $institution->id,
            'status' => 'active',
        ]);
        PolicyRule::factory()->create([
            'filtering_policy_id' => $schoolPolicy->id,
            'content_category_id' => $category->id,
            'action' => 'allow',
        ]);

        $this->assertContains('porn.example', app(EffectivePolicyResolver::class)->resolve($institution)->blockedDomains);
    }

    public function test_a_learner_group_policy_is_narrower_than_the_schools_own(): void
    {
        $institution = Institution::factory()->create();
        $group = LearnerGroup::factory()->create(['institution_id' => $institution->id]);
        $category = ContentCategory::factory()->create();
        $source = $this->source($category);
        BlockedDomain::create(['blocklist_source_id' => $source->id, 'domain' => 'forum.example']);

        $schoolPolicy = FilteringPolicy::factory()->create([
            'level' => 'institution',
            'institution_id' => $institution->id,
            'status' => 'active',
        ]);
        PolicyRule::factory()->create([
            'filtering_policy_id' => $schoolPolicy->id,
            'content_category_id' => $category->id,
            'action' => 'block',
        ]);

        $groupPolicy = FilteringPolicy::factory()->create([
            'level' => 'group',
            'institution_id' => $institution->id,
            'learner_group_id' => $group->id,
            'status' => 'active',
        ]);
        PolicyRule::factory()->create([
            'filtering_policy_id' => $groupPolicy->id,
            'content_category_id' => $category->id,
            'action' => 'allow',
        ]);

        $resolver = app(EffectivePolicyResolver::class);

        $this->assertContains('forum.example', $resolver->resolve($institution)->blockedDomains);
        $this->assertNotContains('forum.example', $resolver->resolve($institution, $group->id)->blockedDomains);
    }

    public function test_an_approved_exception_unblocks_a_domain_until_it_expires(): void
    {
        $institution = Institution::factory()->create();
        $source = $this->source(ContentCategory::factory()->create());
        BlockedDomain::create(['blocklist_source_id' => $source->id, 'domain' => 'research.example']);

        $resolver = app(EffectivePolicyResolver::class);
        $advisor = app(DomainAdvisor::class);

        $this->assertContains('research.example', $resolver->resolve($institution)->blockedDomains);

        $exception = ExceptionRequest::factory()->reviewed()->create([
            'institution_id' => $institution->id,
            'domain' => 'research.example',
            'expires_at' => now()->addDay(),
        ]);

        $blockedRevision = $resolver->resolve($institution)->revision;
        $allowedPolicy = $resolver->resolve($institution);
        $this->assertNotContains('research.example', $allowedPolicy->blockedDomains);
        $this->assertContains('research.example', $allowedPolicy->allowedDomains);
        $this->assertSame('allowlist', $advisor->assess('https://research.example/', $institution->id)['source']);

        $this->travel(2)->days();

        $expiredPolicy = $resolver->resolve($institution);
        $this->assertContains('research.example', $expiredPolicy->blockedDomains);
        $this->assertGreaterThan($blockedRevision, $expiredPolicy->revision);
        $this->assertSame('blocklist', $advisor->assess('https://research.example/', $institution->id)['source']);

        $this->assertSame('research.example', $exception->fresh()->domain);
    }

    public function test_an_exception_outranks_a_parent_domain_block_through_a_browser_allow_rule(): void
    {
        $institution = Institution::factory()->create();
        $source = $this->source(ContentCategory::factory()->create());
        BlockedDomain::create(['blocklist_source_id' => $source->id, 'domain' => 'example.com']);

        ExceptionRequest::factory()->reviewed()->create([
            'institution_id' => $institution->id,
            'domain' => 'lessons.example.com',
        ]);

        $policy = app(EffectivePolicyResolver::class)->resolve($institution);
        $browser = app(BrowserPolicyCompiler::class)->compile($policy);

        $allowRule = collect($browser['dnr_rules'])
            ->firstWhere('condition.urlFilter', '||lessons.example.com^');
        $blockRule = collect($browser['dnr_rules'])
            ->firstWhere('condition.urlFilter', '||example.com^');

        $this->assertNotNull($allowRule);
        $this->assertNotNull($blockRule);
        $this->assertSame('allow', $allowRule['action']['type']);
        $this->assertGreaterThan($blockRule['priority'], $allowRule['priority']);

        $advisor = app(DomainAdvisor::class);
        $this->assertSame('allowlist', $advisor->assess('https://lessons.example.com/', $institution->id)['source']);
        $this->assertSame('blocklist', $advisor->assess('https://adverts.example.com/', $institution->id)['source']);
    }

    public function test_the_curriculum_allowlist_is_never_blocked_by_an_upstream_list(): void
    {
        $institution = Institution::factory()->create();
        $source = $this->source(ContentCategory::factory()->create());
        BlockedDomain::create(['blocklist_source_id' => $source->id, 'domain' => 'wikipedia.org']);

        $policy = app(EffectivePolicyResolver::class)->resolve($institution);

        $this->assertNotContains('wikipedia.org', $policy->blockedDomains);
        $this->assertContains('wikipedia.org', $policy->allowedDomains);
    }

    private function source(ContentCategory $category): BlocklistSource
    {
        return BlocklistSource::create([
            'slug' => fake()->unique()->slug(),
            'name' => fake()->words(2, true),
            'url' => 'https://raw.githubusercontent.com/'.fake()->unique()->slug().'/hosts',
            'content_category_id' => $category->id,
            'provenance' => 'test fixture',
            'is_enabled' => true,
        ]);
    }
}
