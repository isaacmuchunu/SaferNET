<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\IncidentActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'incident_id', 'actor_id', 'action', 'notes', 'metadata'])]
class IncidentAction extends Model
{
    /** @use HasFactory<IncidentActionFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    protected static function booted(): void
    {
        static::creating(function (IncidentAction $action): void {
            $action->institution_id ??= Incident::query()->findOrFail($action->incident_id)->institution_id;
        });
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
