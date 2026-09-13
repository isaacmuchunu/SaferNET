<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\Tenancy\TenantContext;
use Database\Factories\FilteringPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_id', 'institution_id', 'learner_group_id', 'created_by', 'name', 'level', 'status', 'version', 'effective_from', 'effective_until'])]
class FilteringPolicy extends Model
{
    /** @use HasFactory<FilteringPolicyFactory> */
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PolicyRule::class);
    }

    public function policyRules(): HasMany
    {
        return $this->rules();
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function learnerGroup(): BelongsTo
    {
        return $this->belongsTo(LearnerGroup::class);
    }

    public function scopeVisibleTo(Builder $query, User $principal): Builder
    {
        if ($principal->hasRole(UserRole::Cde)) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($principal): void {
            $scope->whereNull('institution_id');

            if ($principal->hasRole(UserRole::Scde)) {
                $scope->orWhereIn('institution_id', Institution::query()
                    ->select('id')
                    ->where('subcounty_id', $principal->subcounty_id));
            } else {
                $scope->orWhere('institution_id', $principal->institution_id);
            }
        });
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
        return ['effective_from' => 'datetime', 'effective_until' => 'datetime'];
    }
}
