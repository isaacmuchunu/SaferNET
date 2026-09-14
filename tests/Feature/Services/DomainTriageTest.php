<?php

namespace Tests\Feature\Services;

use App\Enums\EnforcementAction;
use App\Enums\Severity;
use App\Models\AuditLog;
use App\Models\BlockedDomain;
use App\Models\BlocklistSource;
use App\Models\ContentCategory;
use App\Models\Device;
use App\Models\DomainReview;
use App\Models\Institution;
use App\Models\Learner;
use App\Models\LearnerSession;
use App\Models\User;
use App\Models\WebEvent;
use App\Services\Ai\ContentAssessment;
use App\Services\Ai\ContentClassifier;
use App\Services\Filtering\EffectivePolicyResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * The classifier advises; an officer decides. These tests hold that line,
 * because a model whose opinion quietly becomes county policy is not a decision
 * anyone made.
 */
class DomainTriageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_domain_two_learners_reached_is_queued_for_review(): void
    {
        $institution = Institution::factory()->create();
        $this->visit($institution, 'newsite.example', 2);

        $this->withClassifierReturning(null);
        $this->artisan('safernet:triage-domains')->assertSuccessful();

        $review = DomainReview::sole();
        $this->assertSame('newsite.example', $review->domain);
        $this->assertSame(2, $review->learners_seen);
        $this->assertSame(DomainReview::Pending, $review->status);
    }

    public function test_one_learners_browsing_alone_is_not_queued(): void
    {
        $institution = Institution::factory()->create();
        $this->visit($institution, 'curious.example', 1);

        $this->withClassifierReturning(null);
        $this->artisan('safernet:triage-domains')->assertSuccessful();

        // Below the threshold a queue entry would single out one child's
        // browsing for official attention.
        $this->assertDatabaseCount('domain_reviews', 0);
    }

    public function test_a_domain_already_on_a_blocklist_is_not_queued(): void
    {
        $institution = Institution::factory()->create();
        $this->visit($institution, 'known-bad.example', 3);

        $source = BlocklistSource::create([
            'slug' => 'upstream', 'name' => 'Upstream', 'url' => 'https://example.test/list',
            'provenance' => 'test', 'is_enabled' => true,
        ]);
        BlockedDomain::create(['blocklist_source_id' => $source->id, 'domain' => 'known-bad.example']);

        $this->withClassifierReturning(null);
        $this->artisan('safernet:triage-domains')->assertSuccessful();

        $this->assertDatabaseCount('domain_reviews', 0);
    }

    public function test_the_classifier_opinion_is_recorded_but_blocks_nothing(): void
    {
        $institution = Institution::factory()->create();
        ContentCategory::factory()->create(['name' => 'Gambling', 'slug' => 'gambling']);
        $this->visit($institution, 'bet-newsite.example', 4);

        $this->withClassifierReturning(new ContentAssessment(
            category: 'Gambling',
            riskScore: 82,
            severity: Severity::High,
            action: EnforcementAction::Block,
            rationale: 'Online betting with account registration.',
            provider: 'test-provider',
            model: 'test-model',
        ));

        $this->artisan('safernet:triage-domains')->assertSuccessful();

        $review = DomainReview::sole();
        $this->assertSame(DomainReview::Classified, $review->status);
        $this->assertSame(EnforcementAction::Block, $review->suggested_action);
        $this->assertSame('test-model', $review->classifier_model);
        $this->assertSame('Gambling', $review->suggestedCategory->name);

        // The decisive assertion: a confident classifier has changed nothing
        // that any device enforces.
        $this->assertDatabaseCount('blocked_domains', 0);
        $this->assertNull($review->reviewed_at);
    }

    public function test_a_director_blocking_a_domain_reaches_every_school(): void
    {
        $institution = Institution::factory()->create();
        $category = ContentCategory::factory()->create(['slug' => 'gambling']);
        $review = DomainReview::create([
            'domain' => 'bet-newsite.example',
            'learners_seen' => 4,
            'status' => DomainReview::Classified,
            'suggested_category_id' => $category->id,
            'suggested_action' => EnforcementAction::Block->value,
            'classifier_model' => 'test-model',
        ]);

        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $this->putJson(route('api.v1.domain-reviews.update', $review), [
            'decision' => 'blocked',
            'review_notes' => 'Confirmed betting site.',
        ])->assertOk();

        // County-wide: it is in the effective policy of a school that had
        // nothing to do with finding it.
        $policy = app(EffectivePolicyResolver::class)->resolve($institution);
        $this->assertContains('bet-newsite.example', $policy->blockedDomains);

        $log = AuditLog::withoutGlobalScopes()->where('event', 'domain_review.decided')->sole();
        $this->assertSame('blocked', $log->new_values['decision']);
        $this->assertSame('test-model', $log->old_values['classifier_model']);
    }

    public function test_a_director_may_overrule_the_classifier(): void
    {
        $suggested = ContentCategory::factory()->create(['slug' => 'gambling', 'name' => 'Gambling']);
        $actual = ContentCategory::factory()->create(['slug' => 'social-media', 'name' => 'Social media']);

        $review = DomainReview::create([
            'domain' => 'misread.example',
            'learners_seen' => 3,
            'status' => DomainReview::Classified,
            'suggested_category_id' => $suggested->id,
            'suggested_action' => EnforcementAction::Block->value,
        ]);

        Sanctum::actingAs(User::factory()->scde()->create(), ['portal:access']);

        $this->putJson(route('api.v1.domain-reviews.update', $review), [
            'decision' => 'allowed',
            'content_category_id' => $actual->id,
            'review_notes' => 'Not gambling; a sports news site.',
        ])->assertOk();

        $review->refresh();
        $this->assertSame(DomainReview::Allowed, $review->status);
        // Both are kept, so the decision can be compared with the advice.
        $this->assertSame($suggested->id, $review->suggested_category_id);
        $this->assertSame($actual->id, $review->content_category_id);
        $this->assertDatabaseCount('blocked_domains', 0);
    }

    public function test_a_decided_domain_is_never_queued_again(): void
    {
        $institution = Institution::factory()->create();
        $this->visit($institution, 'settled.example', 3);

        DomainReview::create([
            'domain' => 'settled.example',
            'status' => DomainReview::Allowed,
            'reviewed_at' => now(),
        ]);

        $this->withClassifierReturning(null);
        $this->artisan('safernet:triage-domains')->assertSuccessful();

        // Re-asking a settled question wastes the reviewer's time and erodes
        // the queue.
        $this->assertSame(DomainReview::Allowed, DomainReview::sole()->status);
    }

    public function test_the_queue_shows_a_director_what_is_waiting(): void
    {
        $category = ContentCategory::factory()->create(['name' => 'Gambling', 'slug' => 'gambling']);

        DomainReview::create([
            'domain' => 'waiting.example',
            'learners_seen' => 6,
            'institutions_seen' => 2,
            'events_seen' => 40,
            'status' => DomainReview::Classified,
            'suggested_category_id' => $category->id,
            'suggested_action' => EnforcementAction::Block->value,
            'suggested_severity' => Severity::High->value,
            'risk_score' => 82,
            'rationale' => 'Online betting.',
            'classifier_provider' => 'test-provider',
            'classifier_model' => 'test-model',
            'classified_at' => now(),
        ]);
        DomainReview::create([
            'domain' => 'settled.example',
            'status' => DomainReview::Allowed,
            'reviewed_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $response = $this->getJson(route('api.v1.domain-reviews.index'))->assertOk();

        // The default view is what still needs a person.
        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.domain', 'waiting.example')
            ->assertJsonPath('data.0.learners_seen', 6)
            ->assertJsonPath('data.0.suggestion.action', EnforcementAction::Block->value)
            ->assertJsonPath('data.0.suggestion.category', 'Gambling')
            // Advice, not a decision: the decision half is empty until an
            // officer fills it.
            ->assertJsonPath('data.0.decision', null)
            ->assertJsonPath('summary.awaiting', 1)
            ->assertJsonPath('summary.allowed', 1);
    }

    public function test_a_head_of_institution_cannot_set_county_policy(): void
    {
        $institution = Institution::factory()->create();
        $review = DomainReview::create(['domain' => 'anything.example', 'status' => DomainReview::Classified]);

        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->getJson(route('api.v1.domain-reviews.index'))->assertForbidden();
        $this->putJson(route('api.v1.domain-reviews.update', $review), ['decision' => 'blocked'])->assertForbidden();
    }

    public function test_a_domain_cannot_be_reviewed_twice(): void
    {
        $review = DomainReview::create([
            'domain' => 'once.example',
            'status' => DomainReview::Blocked,
            'reviewed_at' => now(),
            'reviewed_by' => User::factory()->cde()->create()->id,
        ]);

        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $this->putJson(route('api.v1.domain-reviews.update', $review), ['decision' => 'allowed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision');
    }

    private function withClassifierReturning(?ContentAssessment $assessment): void
    {
        $classifier = Mockery::mock(ContentClassifier::class);
        $classifier->shouldReceive('classify')->andReturn($assessment);
        $classifier->shouldReceive('isEnabled')->andReturn($assessment !== null);

        $this->app->instance(ContentClassifier::class, $classifier);
    }

    /** Records one domain being reached by a number of distinct learners. */
    private function visit(Institution $institution, string $domain, int $learners): void
    {
        foreach (range(1, $learners) as $index) {
            $device = Device::factory()->for($institution)->create();
            $learner = Learner::factory()->for($institution)->create();
            $session = LearnerSession::create([
                'institution_id' => $institution->id,
                'device_id' => $device->id,
                'learner_id' => $learner->id,
                'identity_source' => 'school_pin',
                'started_at' => now()->subHour(),
            ]);

            WebEvent::create([
                'event_uuid' => (string) Str::uuid(),
                'institution_id' => $institution->id,
                'learner_session_id' => $session->id,
                'learner_id' => $learner->id,
                'device_id' => $device->id,
                'url' => "https://{$domain}/",
                'domain' => $domain,
                'request_kind' => 'top_level',
                'action' => 'allow',
                'enforcement_source' => 'extension',
                'severity' => 'low',
                'reason' => 'Learner navigation',
                'occurred_at' => now()->subMinutes(10),
            ]);
        }
    }
}
