<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearnerSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'identity_source' => $this->identity_source,
            'started_at' => $this->started_at,
            'last_activity_at' => $this->last_activity_at,
            'ended_at' => $this->ended_at,
            'device' => new DeviceResource($this->whenLoaded('device')),
            'learner' => $this->whenLoaded('learner', fn () => [
                'id' => $this->learner->id,
                'learner_number' => $this->learner->learner_number,
                'name' => $this->learner->full_name,
                'group' => $this->learner->learnerGroup?->name,
            ]),
            'attribution' => 'system_attribution',
        ];
    }
}
