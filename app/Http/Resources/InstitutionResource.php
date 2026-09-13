<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstitutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'nemis_code' => $this->nemis_code,
            'institution_type' => $this->institution_type,
            'ownership' => $this->ownership,
            'status' => $this->status,
            'physical_location' => $this->physical_location,
            'hoi' => ['name' => $this->hoi_name, 'email' => $this->hoi_email, 'phone' => $this->hoi_phone],
            'learner_population' => $this->learner_population,
            'computing_devices_count' => $this->computing_devices_count,
            'laboratories_count' => $this->laboratories_count,
            'subcounty' => new SubcountyResource($this->whenLoaded('subcounty')),
            'learners_count' => $this->whenCounted('learners'),
            'devices_count' => $this->whenCounted('devices'),
            'submitted_at' => $this->submitted_at,
        ];
    }
}
