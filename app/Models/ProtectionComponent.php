<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\ProtectionComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'device_id', 'type', 'identifier', 'version', 'health_status', 'last_seen_at', 'policy_synced_at', 'metadata'])]
class ProtectionComponent extends Model
{
    /** @use HasFactory<ProtectionComponentFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'policy_synced_at' => 'datetime', 'metadata' => 'array'];
    }
}
