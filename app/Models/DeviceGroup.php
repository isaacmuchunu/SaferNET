<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\DeviceGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'name', 'purpose'])]
class DeviceGroup extends Model
{
    /** @use HasFactory<DeviceGroupFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
