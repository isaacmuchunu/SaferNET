<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_path
                ? Storage::disk('public')->url($this->avatar_path)
                : null,
            'role' => $this->role,
            'status' => $this->status,
            'mfa_enabled' => $this->mfa_enabled_at !== null,
            'onboarding_pending' => $this->must_change_password || ($this->mfa_required && $this->mfa_enabled_at === null),
            'subcounty' => new SubcountyResource($this->whenLoaded('subcounty')),
            'institution' => new InstitutionResource($this->whenLoaded('institution')),
        ];
    }
}
