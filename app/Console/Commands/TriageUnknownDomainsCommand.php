<?php

namespace App\Console\Commands;

use App\Models\BlockedDomain;
use App\Models\ContentCategory;
use App\Models\DomainReview;
use App\Models\WebEvent;
use App\Services\Ai\ContentAssessment;
use App\Services\Ai\ContentClassifier;
use App\Services\Filtering\EffectivePolicyResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Finds domains learners actually reached that no blocklist knows about, and
 * asks the classifier for an opinion on each.
 *
 * This is where the classifier earns its place. It is not in the enforcement
 * path — a model in the blocking path adds latency to every page load and fails
 * open when the provider is slow — so instead it runs here, once per domain,
 * behind the request. A harmful verdict becomes a suggestion for an officer,
 * and only an officer's decision becomes county policy.
 *
 * The threshold matters as much as the classification. One curious learner
 * generates a long tail of one-off domains; a queue containing all of them is a
 * queue nobody reads. Requiring a domain to have been reached by more than one
 * learner keeps the queue about patterns rather than individuals — and it means
 * the queue never singles out one child's browsing for official attention.
 */
class TriageUnknownDomainsCommand extends Command
{
    protected $signature = 'safernet:triage-domains
        {--days=7 : How far back to look for unclassified activity}
        {--limit=50 : How many new domains to classify in one run}';

    protected $description = 'Queue domains seen by learners that no blocklist covers, with a classifier opinion for review';

    public function handle(ContentClassifier $classifier, EffectivePolicyResolver $resolver): int
    {
        $minimumLearners = (int) config('filtering.triage.minimum_learners', 2);
        $since = now()->subDays((int) $this->option('days'));

        $candidates = $this->unknownDomains($since, $minimumLearners, (int) $this->option('limit'));

        if ($candidates->isEmpty()) {
            $this->info('No new unclassified domains met the threshold.');

            return self::SUCCESS;
        }

        $queued = 0;
        $classified = 0;

        foreach ($candidates as $candidate) {
            $review = DomainReview::query()->updateOrCreate(
                ['domain' => $candidate->domain],
                [
                    'learners_seen' => $candidate->learners_seen,
                    'institutions_seen' => $candidate->institutions_seen,
                    'events_seen' => $candidate->events_seen,
                    'first_seen_at' => $candidate->first_seen_at,
                    'last_seen_at' => $candidate->last_seen_at,
                ],
            );

            if ($review->wasRecentlyCreated) {
                $queued++;
            }

            // Only ask once per domain. The answer does not change often enough
            // to be worth paying for on every run.
            if ($review->classified_at !== null) {
                continue;
            }

            $assessment = $classifier->classify('https://'.$candidate->domain);

            if ($assessment === null) {
                // The classifier fails open by design. The domain stays queued
                // and an officer reviews it without a suggestion.
                continue;
            }

            $review->update([
                'status' => DomainReview::Classified,
                'suggested_category_id' => $this->categoryFor($assessment)?->id,
                'suggested_action' => $assessment->action,
                'suggested_severity' => $assessment->severity,
                'risk_score' => $assessment->riskScore,
                'rationale' => $assessment->rationale,
                'classifier_provider' => $assessment->provider,
                'classifier_model' => $assessment->model,
                'classified_at' => now(),
            ]);

            $classified++;
        }

        $this->info("Queued {$queued} new domain(s); classified {$classified}.");

        if (! $classifier->isEnabled()) {
            $this->warn('No classifier provider is configured, so domains were queued without a suggestion.');
        }

        return self::SUCCESS;
    }

    /**
     * Domains reached by at least the threshold number of distinct learners
     * that appear on no blocklist and have not already been decided.
     *
     * @return Collection<int, object>
     */
    private function unknownDomains($since, int $minimumLearners, int $limit)
    {
        return WebEvent::query()
            ->withoutGlobalScopes()
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('domain')
            ->select('domain')
            ->selectRaw('COUNT(DISTINCT learner_id) AS learners_seen')
            ->selectRaw('COUNT(DISTINCT institution_id) AS institutions_seen')
            ->selectRaw('COUNT(*) AS events_seen')
            ->selectRaw('MIN(occurred_at) AS first_seen_at')
            ->selectRaw('MAX(occurred_at) AS last_seen_at')
            // Already blocked somewhere: nothing to decide.
            ->whereNotIn('domain', BlockedDomain::query()->select('domain'))
            // Already decided by an officer: not queued again.
            ->whereNotIn('domain', DomainReview::query()
                ->whereIn('status', [DomainReview::Blocked, DomainReview::Allowed])
                ->select('domain'))
            ->groupBy('domain')
            ->havingRaw('COUNT(DISTINCT learner_id) >= ?', [$minimumLearners])
            ->orderByDesc('learners_seen')
            ->limit($limit)
            ->get();
    }

    /**
     * Maps the classifier's own vocabulary onto the county's content
     * categories, which are what policy is written against.
     */
    private function categoryFor(ContentAssessment $assessment): ?ContentCategory
    {
        $slug = match ($assessment->category) {
            'Explicit/Adult' => 'pornography',
            'Circumvention/VPN' => 'proxy-anonymizers',
            'Gambling' => 'gambling',
            'Self-Harm' => 'self-harm',
            'School Violence' => 'violence',
            'Cyberbullying' => 'cyberbullying',
            'Controlled Substances' => 'controlled-substances',
            default => null,
        };

        return $slug === null
            ? null
            : ContentCategory::query()->where('slug', $slug)->first();
    }
}
