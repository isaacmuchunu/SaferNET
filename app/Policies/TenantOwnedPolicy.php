<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\FilteringPolicy;
use App\Models\Incident;
use App\Models\PolicyRule;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

class TenantOwnedPolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return ! $user->hasRole(UserRole::Service);
    }

    public function view(User $user, Model $model): bool
    {
        if ($user->hasRole(UserRole::Service)) {
            return false;
        }

        if ($model instanceof PolicyRule) {
            return $model->policy()->visibleTo($user)->exists();
        }

        if ($model instanceof FilteringPolicy && $model->institution_id === null) {
            return true;
        }

        return $this->tenantContext->canAccessInstitution((int) $model->getAttribute('institution_id'), $user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Cde, UserRole::Hoi, UserRole::Clm);
    }

    /** Only the accountable officer may author filtering policy. */
    public function createPolicy(User $user): bool
    {
        return $user->hasRole(UserRole::Cde, UserRole::Hoi);
    }

    public function update(User $user, Model $model): bool
    {
        if ($model instanceof FilteringPolicy && $model->institution_id === null) {
            return $user->hasRole(UserRole::Cde);
        }

        if ($model instanceof PolicyRule && $model->policy()->whereNull('institution_id')->exists()) {
            return $user->hasRole(UserRole::Cde);
        }

        // Filtering rules govern what a school may do, and acting on a learner
        // incident is an administrative decision. Both stay with the accountable
        // officer; the laboratory manager operates the technology.
        if ($model instanceof FilteringPolicy || $model instanceof PolicyRule || $model instanceof Incident) {
            return $user->hasRole(UserRole::Cde, UserRole::Hoi) && $this->view($user, $model);
        }

        return $user->hasRole(UserRole::Cde, UserRole::Hoi, UserRole::Clm)
            && $this->view($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->view($user, $model);
    }
}
