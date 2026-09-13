<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['institution_id', 'laboratory_id', 'device_group_id', 'asset_tag', 'serial_number', 'hostname', 'platform', 'usage_type', 'status', 'last_seen_at'])]
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Device $device): void {
            $device->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class);
    }

    public function deviceGroup(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceLearnerAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('removed_at');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LearnerSession::class);
    }

    /** The learner session currently signed in on this device, if any. */
    public function activeSessions(): HasMany
    {
        return $this->sessions()->whereNull('ended_at')->latest('started_at');
    }

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
