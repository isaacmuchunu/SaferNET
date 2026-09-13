<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Subcounty;
use App\Models\User;

class SubcountyPolicy
{
    public function view(User $user, Subcounty $subcounty): bool
    {
        return $user->hasRole(UserRole::Cde)
            || ($user->hasRole(UserRole::Scde, UserRole::Hoi, UserRole::Clm)
                && $user->subcounty_id === $subcounty->id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Cde);
    }

    public function update(User $user, Subcounty $subcounty): bool
    {
        return $user->hasRole(UserRole::Cde);
    }
}
