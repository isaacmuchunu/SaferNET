<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'severity' => $this->severity,
            'status' => $this->status,
            'event_count' => $this->event_count,
            'first_detected_at' => $this->first_detected_at,
            'last_detected_at' => $this->last_detected_at,
            'notified_at' => $this->notified_at,
            'resolved_at' => $this->resolved_at,
            'resolution_summary' => $this->resolution_summary,
            'assigned_to' => $this->assigned_to,
            'institution' => $this->whenLoaded('institution', fn (): array => [
                'id' => $this->institution->id,
                'name' => $this->institution->name,
            ]),
            'learner' => $this->whenLoaded('learner', fn (): array => [
                'id' => $this->learner->id,
                'learner_number' => $this->learner->learner_number,
                'name' => $this->learner->full_name,
            ]),
            'device' => $this->whenLoaded('device', fn (): array => [
                'id' => $this->device->public_id,
                'asset_tag' => $this->device->asset_tag,
            ]),
            'category' => $this->whenLoaded('category', fn (): ?array => $this->category === null ? null : [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'actions' => $this->whenLoaded('actions', fn () => $this->actions->map(fn ($action): array => [
                'id' => $action->id,
                'action' => $action->action,
                'notes' => $action->notes,
                'recorded_at' => $action->created_at,
                'actor' => $action->actor?->name,
            ])),
            'web_events' => $this->whenLoaded('webEvents', fn () => $this->webEvents->map(fn ($event): array => [
                'id' => $event->event_uuid,
                'domain' => $event->domain,
                'url' => $event->url,
                'action' => $event->action,
                'severity' => $event->severity,
                'request_kind' => $event->request_kind,
                'occurred_at' => $event->occurred_at,
            ])),
        ];
    }
}
