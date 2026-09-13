<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SubcountyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['name', 'code', 'is_active'])]
class Subcounty extends Model
{
    /** @use HasFactory<SubcountyFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $tenantContext = app(TenantContext::class);

            if ($tenantContext->resolved()) {
                $query->visibleTo($tenantContext->principal());
            }
        });
    }

    public function institutions(): HasMany
    {
        return $this->hasMany(Institution::class);
    }

    public function devices(): HasManyThrough
    {
        return $this->hasManyThrough(Device::class, Institution::class);
    }

    public function learners(): HasManyThrough
    {
        return $this->hasManyThrough(Learner::class, Institution::class);
    }

    public function incidents(): HasManyThrough
    {
        return $this->hasManyThrough(Incident::class, Institution::class);
    }

    public function scopeVisibleTo(Builder $query, User $principal): Builder
    {
        return $principal->hasRole(UserRole::Cde)
            ? $query
            : $query->whereKey($principal->subcounty_id);
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $principal = request()->user();

        return $principal instanceof User
            ? $query->visibleTo($principal)
            : $query->whereRaw('1 = 0');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
