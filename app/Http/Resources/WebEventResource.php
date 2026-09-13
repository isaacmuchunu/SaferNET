<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->event_uuid,
            'session_id' => $this->learner_session_id,
            'learner_id' => $this->learner_id,
            'device_id' => $this->device_id,
            'url' => $this->url,
            'domain' => $this->domain,
            'request_kind' => $this->request_kind,
            'action' => $this->action,
            'enforcement_source' => $this->enforcement_source,
            'severity' => $this->severity,
            'reason' => $this->reason,
            'occurred_at' => $this->occurred_at,
            'incident' => new IncidentResource($this->whenLoaded('incident')),
        ];
    }
}
