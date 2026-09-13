<?php

namespace App\Models;

use App\Enums\InstitutionStatus;
use App\Support\Tenancy\TenantContext;
use Database\Factories\InstitutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['subcounty_id', 'submitted_by', 'reviewed_by', 'name', 'nemis_code', 'institution_type', 'ownership', 'status', 'physical_location', 'hoi_name', 'hoi_email', 'hoi_phone', 'learner_population', 'computing_devices_count', 'laboratories_count', 'connectivity_type', 'review_notes', 'submitted_at', 'reviewed_at'])]
class Institution extends Model
{
    /** @use HasFactory<InstitutionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $tenantContext = app(TenantContext::class);

            if ($tenantContext->resolved()) {
                $tenantContext->scopeInstitutions($query);
            }
        });
    }

    public function subcounty(): BelongsTo
    {
        return $this->belongsTo(Subcounty::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function learners(): HasMany
    {
        return $this->hasMany(Learner::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function scopeVisibleTo(Builder $query, User $principal): Builder
    {
        return app(TenantContext::class)->scopeInstitutions($query, $principal);
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $principal = request()->user();

        return $principal instanceof User
            ? app(TenantContext::class)->scopeInstitutions($query, $principal)
            : $query->whereRaw('1 = 0');
    }

    protected function casts(): array
    {
        return [
            'status' => InstitutionStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
