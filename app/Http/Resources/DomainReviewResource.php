<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A queued domain, with the classifier's advice and the officer's decision kept
 * visibly apart.
 *
 * The separation is the point: a reviewer has to be able to see what the model
 * proposed, what a person decided, and where the two differed. Flattening them
 * into one "category" would erase exactly the thing the record exists to show.
 */
class DomainReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'status' => $this->status,

            'learners_seen' => $this->learners_seen,
            'institutions_seen' => $this->institutions_seen,
            'events_seen' => $this->events_seen,
            'first_seen_at' => $this->first_seen_at,
            'last_seen_at' => $this->last_seen_at,

            'suggestion' => $this->classified_at === null ? null : [
                'category' => $this->whenLoaded('suggestedCategory', fn () => $this->suggestedCategory?->name),
                'category_id' => $this->suggested_category_id,
                'action' => $this->suggested_action,
                'severity' => $this->suggested_severity,
                'risk_score' => $this->risk_score,
                'rationale' => $this->rationale,
                'provider' => $this->classifier_provider,
                'model' => $this->classifier_model,
                'classified_at' => $this->classified_at,
            ],

            'decision' => $this->reviewed_at === null ? null : [
                'category' => $this->whenLoaded('category', fn () => $this->category?->name),
                'category_id' => $this->content_category_id,
                'notes' => $this->review_notes,
                'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
                'reviewed_at' => $this->reviewed_at,
            ],
        ];
    }
}
