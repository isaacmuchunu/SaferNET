<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToInstitutionTenant
{
    public static function bootBelongsToInstitutionTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $tenantContext = app(TenantContext::class);

            if ($tenantContext->resolved()) {
                $model = $query->getModel();
                $tenantContext->scopeInstitutionOwned($query, $model->qualifyColumn('institution_id'));
            }
        });
    }

    public function scopeVisibleTo(Builder $query, User $principal): Builder
    {
        return app(TenantContext::class)->scopeInstitutionOwned(
            $query,
            $this->qualifyColumn('institution_id'),
            $principal,
        );
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $principal = request()->user();

        if (! $principal instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return app(TenantContext::class)->scopeInstitutionOwned(
            $query,
            $this->qualifyColumn('institution_id'),
            $principal,
        );
    }
}
