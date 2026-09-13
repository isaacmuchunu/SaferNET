<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->hasRole(UserRole::Service);
    }

    public function view(User $user, User $target): bool
    {
        if ($user->hasRole(UserRole::Service)) {
            return false;
        }

        if ($user->hasRole(UserRole::Cde)) {
            return true;
        }

        if ($user->hasRole(UserRole::Scde)) {
            return $target->subcounty_id === $user->subcounty_id
                && ! $target->hasRole(UserRole::Cde);
        }

        return $target->institution_id === $user->institution_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Cde, UserRole::Scde, UserRole::Hoi);
    }

    public function update(User $user, User $target): bool
    {
        // Every officer may maintain their own profile and password.
        if ($user->id === $target->id) {
            return true;
        }

        return $this->create($user) && $this->view($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->id !== $target->id && $this->create($user) && $this->view($user, $target);
    }
}
