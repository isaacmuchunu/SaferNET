<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\DeviceLearnerAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'device_id', 'learner_id', 'assigned_by', 'removed_by', 'assigned_at', 'removed_at', 'removal_reason'])]
class DeviceLearnerAssignment extends Model
{
    /** @use HasFactory<DeviceLearnerAssignmentFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    protected static function booted(): void
    {
        static::creating(function (DeviceLearnerAssignment $assignment): void {
            $assignment->institution_id ??= Device::query()->findOrFail($assignment->device_id)->institution_id;
        });
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'removed_at' => 'datetime'];
    }
}
