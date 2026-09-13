<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\User;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->hasRole(UserRole::Service);
    }

    public function view(User $user, Device $device): bool
    {
        return $user->hasRole(UserRole::Cde)
            || ($user->hasRole(UserRole::Scde) && $user->subcounty_id === $device->institution->subcounty_id)
            || ($user->hasRole(UserRole::Hoi, UserRole::Clm) && $user->institution_id === $device->institution_id);
    }

    public function assignLearner(User $user, Device $device): bool
    {
        return $user->hasRole(UserRole::Cde)
            || ($user->hasRole(UserRole::Hoi, UserRole::Clm) && $user->institution_id === $device->institution_id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Cde, UserRole::Hoi, UserRole::Clm);
    }

    public function update(User $user, Device $device): bool
    {
        return $this->create($user) && $this->view($user, $device) && ! $user->hasRole(UserRole::Scde);
    }

    public function delete(User $user, Device $device): bool
    {
        return $this->update($user, $device);
    }
}
