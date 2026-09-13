<?php

namespace App\Support\Tenancy;

use App\Enums\UserRole;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use LogicException;

class TenantContext
{
    private ?User $principal = null;

    public function resolved(): bool
    {
        return $this->principal !== null;
    }

    public function setPrincipal(User $principal): void
    {
        $this->assertValidPrincipal($principal);
        $this->principal = $principal;
    }

    public function principal(): User
    {
        return $this->principal ?? throw new LogicException('Tenant context has not been resolved.');
    }

    public function canAccessSubcounty(int $subcountyId, ?User $principal = null): bool
    {
        $principal ??= $this->principal();

        return $principal->hasRole(UserRole::Cde)
            || $principal->subcounty_id === $subcountyId;
    }

    public function canAccessInstitution(int $institutionId, ?User $principal = null): bool
    {
        $principal ??= $this->principal();

        if ($principal->hasRole(UserRole::Cde)) {
            return true;
        }

        if ($principal->hasRole(UserRole::Scde)) {
            return Institution::query()
                ->whereKey($institutionId)
                ->where('subcounty_id', $principal->subcounty_id)
                ->exists();
        }

        return $principal->institution_id === $institutionId;
    }

    public function scopeInstitutions(Builder $query, ?User $principal = null): Builder
    {
        $principal ??= $this->principal();

        if ($principal->hasRole(UserRole::Cde)) {
            return $query;
        }

        if ($principal->hasRole(UserRole::Scde)) {
            return $query->where('subcounty_id', $principal->subcounty_id);
        }

        return $query->whereKey($principal->institution_id);
    }

    public function scopeInstitutionOwned(Builder $query, string $column, ?User $principal = null): Builder
    {
        $principal ??= $this->principal();

        if ($principal->hasRole(UserRole::Cde)) {
            return $query;
        }

        if ($principal->hasRole(UserRole::Scde)) {
            return $query->whereIn($column, Institution::query()
                ->select('id')
                ->where('subcounty_id', $principal->subcounty_id));
        }

        return $query->where($column, $principal->institution_id);
    }

    public function scopeUsers(Builder $query, ?User $principal = null): Builder
    {
        $principal ??= $this->principal();

        if ($principal->hasRole(UserRole::Cde)) {
            return $query;
        }

        if ($principal->hasRole(UserRole::Scde)) {
            return $query->where('subcounty_id', $principal->subcounty_id)
                ->where('role', '!=', UserRole::Cde->value);
        }

        return $query->where('institution_id', $principal->institution_id);
    }

    public function cacheKey(): string
    {
        $principal = $this->principal();

        return match ($principal->role) {
            UserRole::Cde => 'county',
            UserRole::Scde => 'subcounty:'.$principal->subcounty_id,
            default => 'institution:'.$principal->institution_id,
        };
    }

    public function mutationInstitutionId(?int $requestedInstitutionId): int
    {
        $principal = $this->principal();

        if ($principal->hasRole(UserRole::Cde)) {
            if ($requestedInstitutionId === null) {
                throw ValidationException::withMessages([
                    'institution_id' => 'An institution is required for a county-level operation.',
                ]);
            }

            abort_unless(Institution::query()->whereKey($requestedInstitutionId)->exists(), 404);

            return $requestedInstitutionId;
        }

        abort_unless($principal->hasRole(UserRole::Hoi, UserRole::Clm, UserRole::Service), 403);
        abort_if($requestedInstitutionId !== null && $requestedInstitutionId !== $principal->institution_id, 404);

        return (int) $principal->institution_id;
    }

    private function assertValidPrincipal(User $principal): void
    {
        $valid = match ($principal->role) {
            UserRole::Cde => $principal->subcounty_id === null && $principal->institution_id === null,
            UserRole::Scde => $principal->subcounty_id !== null && $principal->institution_id === null,
            UserRole::Hoi, UserRole::Clm, UserRole::Service => $principal->subcounty_id !== null
                && $principal->institution_id !== null
                && Institution::query()
                    ->whereKey($principal->institution_id)
                    ->where('subcounty_id', $principal->subcounty_id)
                    ->exists(),
        };

        abort_unless($valid, 403, 'The account has an invalid tenant assignment.');
    }
}
