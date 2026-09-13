<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\LearnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'learner_group_id', 'learner_number', 'first_name', 'last_name', 'pin_hash', 'external_identity', 'status'])]
#[Hidden(['pin_hash'])]
class Learner extends Model
{
    /** @use HasFactory<LearnerFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function learnerGroup(): BelongsTo
    {
        return $this->belongsTo(LearnerGroup::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceLearnerAssignment::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LearnerSession::class);
    }

    public function webEvents(): HasMany
    {
        return $this->hasMany(WebEvent::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
