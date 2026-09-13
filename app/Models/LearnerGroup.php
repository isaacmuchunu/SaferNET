<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\LearnerGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'name', 'grade_level', 'academic_year'])]
class LearnerGroup extends Model
{
    /** @use HasFactory<LearnerGroupFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function learners(): HasMany
    {
        return $this->hasMany(Learner::class);
    }
}
