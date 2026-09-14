<?php

namespace App\Models;

use App\Enums\EnforcementAction;
use App\Enums\Severity;
use Database\Factories\DomainReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A domain learners reached that no blocklist knows about, awaiting a decision.
 *
 * The classifier's opinion and the officer's decision are separate columns on
 * purpose. A reviewer may agree, disagree, or decide the classifier was wrong
 * about the category, and the record has to show which — an AI suggestion that
 * silently becomes county policy is not a decision anyone made.
 */
#[Fillable([
    'domain', 'learners_seen', 'institutions_seen', 'events_seen', 'first_seen_at', 'last_seen_at',
    'status', 'suggested_category_id', 'suggested_action', 'suggested_severity', 'risk_score',
    'rationale', 'classifier_provider', 'classifier_model', 'classified_at',
    'reviewed_by', 'reviewed_at', 'review_notes', 'content_category_id',
])]
class DomainReview extends Model
{
    /** @use HasFactory<DomainReviewFactory> */
    use HasFactory;

    /** Seen, not yet classified. */
    public const Pending = 'pending';

    /** The classifier has offered an opinion; awaiting an officer. */
    public const Classified = 'classified';

    /** An officer blocked it county-wide. */
    public const Blocked = 'blocked';

    /** An officer judged it acceptable; it is not queued again. */
    public const Allowed = 'allowed';

    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'suggested_category_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'content_category_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Everything still waiting on a person. */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->whereIn('status', [self::Pending, self::Classified]);
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'classified_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'suggested_action' => EnforcementAction::class,
            'suggested_severity' => Severity::class,
        ];
    }
}
