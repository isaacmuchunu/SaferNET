<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\DomainReviewResource;
use App\Models\AuditLog;
use App\Models\BlockedDomain;
use App\Models\BlocklistSource;
use App\Models\DomainReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The review queue for domains no blocklist covers.
 *
 * A decision here is county-wide: blocking a domain for one school blocks it
 * for all of them, which is the point — one school's discovery protects every
 * school. That is also why only a County or Sub-County Director may decide. A
 * Head of Institution cannot set policy beyond their own gate, and this is not
 * their own gate.
 */
class DomainReviewController extends Controller
{
    /** The blocklist source county decisions are written into. */
    private const ReviewSourceSlug = 'safernet-county-review';

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authoriseReviewer($request);

        $request->validate([
            'status' => ['nullable', 'string', 'in:pending,classified,blocked,allowed,awaiting'],
            'search' => ['nullable', 'string', 'max:253'],
        ]);

        $status = $request->string('status')->toString();

        $reviews = DomainReview::query()
            ->with(['suggestedCategory:id,name', 'category:id,name', 'reviewer:id,name'])
            ->when($status === 'awaiting' || $status === '', fn ($query) => $query->awaitingReview())
            ->when($status !== '' && $status !== 'awaiting', fn ($query) => $query->where('status', $status))
            ->when($request->filled('search'), fn ($query) => $query->where('domain', 'like', '%'.$request->string('search').'%'))
            // Most widely seen first: a domain a year group reached matters
            // more than one a single learner stumbled onto.
            ->orderByDesc('learners_seen')
            ->orderByDesc('last_seen_at');

        return DomainReviewResource::collection($reviews->paginate()->withQueryString())
            ->additional(['summary' => $this->summary()]);
    }

    /**
     * Record the decision.
     *
     * Blocking writes the domain into the county review blocklist, which every
     * school's effective policy already draws from, so the next sync carries it
     * to every agent and browser. Allowing records the judgement so the domain
     * is not queued again — a decision not to block is still a decision, and
     * re-asking would waste the reviewer's time and erode the queue.
     */
    public function update(Request $request, DomainReview $domainReview): DomainReviewResource
    {
        $reviewer = $this->authoriseReviewer($request);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:blocked,allowed'],
            'content_category_id' => ['nullable', 'integer', 'exists:content_categories,id'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($domainReview->reviewed_at !== null) {
            throw ValidationException::withMessages([
                'decision' => 'This domain has already been reviewed.',
            ]);
        }

        // The classifier's category is a default, not the decision: a reviewer
        // may correct it, and the record keeps both.
        $categoryId = $validated['content_category_id'] ?? $domainReview->suggested_category_id;

        DB::transaction(function () use ($domainReview, $validated, $categoryId, $reviewer): void {
            $domainReview->update([
                'status' => $validated['decision'],
                'content_category_id' => $categoryId,
                'review_notes' => $validated['review_notes'] ?? null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            if ($validated['decision'] === DomainReview::Blocked) {
                BlockedDomain::query()->firstOrCreate([
                    'blocklist_source_id' => $this->reviewSource($categoryId)->id,
                    'domain' => $domainReview->domain,
                ]);
            }

            AuditLog::create([
                'actor_id' => $reviewer->id,
                'institution_id' => null,
                'event' => 'domain_review.decided',
                'auditable_type' => DomainReview::class,
                'auditable_id' => $domainReview->id,
                'old_values' => [
                    // What the classifier said, so a decision can be compared
                    // against the advice it was given.
                    'suggested_action' => $domainReview->getOriginal('suggested_action'),
                    'suggested_category_id' => $domainReview->getOriginal('suggested_category_id'),
                    'classifier_model' => $domainReview->classifier_model,
                ],
                'new_values' => [
                    'domain' => $domainReview->domain,
                    'decision' => $validated['decision'],
                    'content_category_id' => $categoryId,
                    'learners_seen' => $domainReview->learners_seen,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });

        return new DomainReviewResource($domainReview->fresh(['suggestedCategory:id,name', 'category:id,name', 'reviewer:id,name']));
    }

    /**
     * How much is waiting, and what has been decided.
     *
     * The queue is only useful if a reviewer can see its size before opening it
     * — a backlog nobody knows about is a backlog nobody clears.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $counts = DomainReview::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'awaiting' => (int) $counts->get(DomainReview::Pending, 0) + (int) $counts->get(DomainReview::Classified, 0),
            'classified' => (int) $counts->get(DomainReview::Classified, 0),
            'blocked' => (int) $counts->get(DomainReview::Blocked, 0),
            'allowed' => (int) $counts->get(DomainReview::Allowed, 0),
        ];
    }

    /**
     * The blocklist source county decisions land in, created on first use.
     *
     * Enabled and county-owned, so the effective policy resolver picks it up
     * like any upstream list — the difference is its provenance names a person
     * rather than a feed.
     */
    private function reviewSource(?int $categoryId): BlocklistSource
    {
        $source = BlocklistSource::query()->firstOrCreate(
            ['slug' => self::ReviewSourceSlug],
            [
                'name' => 'SAFERNET — County review decisions',
                'url' => 'https://safernet.local/county-review',
                'content_category_id' => $categoryId,
                'description' => 'Domains blocked by a county or sub-county director after review of learner activity.',
                'provenance' => 'Reviewed by county officers from real learner traffic',
                'is_enabled' => true,
            ],
        );

        // Keep a category on the source so a domain blocked here is governed by
        // the same category rules as anything from an upstream feed.
        if ($source->content_category_id === null && $categoryId !== null) {
            $source->update(['content_category_id' => $categoryId]);
        }

        return $source;
    }

    private function authoriseReviewer(Request $request)
    {
        $user = $request->user();

        // County-wide policy is a county decision. A Head of Institution
        // governs their own school, which this is not.
        abort_unless($user?->hasRole(UserRole::Cde, UserRole::Scde), 403);

        return $user;
    }
}
