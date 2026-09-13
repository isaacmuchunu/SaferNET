<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\SecurityEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_uuid', 'institution_id', 'device_id', 'learner_session_id', 'learner_id', 'incident_id', 'type', 'severity', 'description', 'response', 'occurred_at', 'metadata'])]
class SecurityEvent extends Model
{
    /** @use HasFactory<SecurityEventFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'metadata' => 'array'];
    }
}
