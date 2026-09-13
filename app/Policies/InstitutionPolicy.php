<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Institution;
use App\Models\User;

class InstitutionPolicy
{
    public function view(User $user, Institution $institution): bool
    {
        return $user->hasRole(UserRole::Cde)
            || ($user->hasRole(UserRole::Scde) && $user->subcounty_id === $institution->subcounty_id)
            || ($user->hasRole(UserRole::Hoi, UserRole::Clm) && $user->institution_id === $institution->id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Cde, UserRole::Scde);
    }

    public function approve(User $user, Institution $institution): bool
    {
        return $user->hasRole(UserRole::Cde);
    }
}
