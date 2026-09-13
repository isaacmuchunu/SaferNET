<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubcountyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'is_active' => $this->is_active,
            'institutions_count' => $this->whenCounted('institutions'),
            'protected_institutions_count' => $this->whenCounted('protectedInstitutions'),
            'learners_count' => $this->whenCounted('learners'),
            'devices_count' => $this->whenCounted('devices'),
            'unattributed_devices_count' => $this->whenCounted('unattributedDevices'),
            'open_incidents_count' => $this->whenCounted('openIncidents'),
        ];
    }
}
