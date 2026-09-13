<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'asset_tag' => $this->asset_tag,
            'hostname' => $this->hostname,
            'platform' => $this->platform,
            'usage_type' => $this->usage_type,
            'status' => $this->status,
            'last_seen_at' => $this->last_seen_at,
            'assigned_learners' => $this->whenLoaded('activeAssignments', fn () => $this->activeAssignments->map(fn ($assignment) => [
                'assignment_id' => $assignment->id,
                'learner_id' => $assignment->learner->id,
                'learner_number' => $assignment->learner->learner_number,
                'name' => $assignment->learner->full_name,
                'group' => $assignment->learner->learnerGroup?->name,
                'assigned_at' => $assignment->assigned_at,
            ])),
            'laboratory_id' => $this->laboratory_id,
            'device_group_id' => $this->device_group_id,
            'serial_number' => $this->serial_number,
            'active_session' => $this->whenLoaded('activeSessions', fn (): ?array => $this->activeSessions->first() === null ? null : [
                'id' => $this->activeSessions->first()->public_id,
                'learner_id' => $this->activeSessions->first()->learner_id,
                'learner' => $this->activeSessions->first()->learner?->full_name,
                'identity_source' => $this->activeSessions->first()->identity_source,
                'started_at' => $this->activeSessions->first()->started_at,
            ]),
            'assignment_capacity' => $this->whenLoaded('activeAssignments', fn () => [
                'used' => $this->activeAssignments->count(),
                'maximum' => 2,
            ]),
        ];
    }
}
