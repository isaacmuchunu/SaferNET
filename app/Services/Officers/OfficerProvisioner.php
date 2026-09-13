<?php

namespace App\Services\Officers;

use App\Jobs\SendOfficerProvisioningMessages;
use App\Models\User;
use App\Services\Notifications\PhoneNumber;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class OfficerProvisioner
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): User
    {
        $temporaryPassword = Str::password(16);
        $attributes['email'] = Str::lower(trim((string) $attributes['email']));
        $attributes['phone'] = PhoneNumber::toE164((string) $attributes['phone']);
        $attributes['password'] = $temporaryPassword;
        $attributes['must_change_password'] = true;
        $attributes['temporary_password_expires_at'] = now()->addHours((int) config('onboarding.temporary_password_hours', 72));
        $attributes['mfa_required'] = true;

        $user = User::create($attributes);

        SendOfficerProvisioningMessages::dispatch(
            $user->getKey(),
            Crypt::encryptString($temporaryPassword),
        )->afterCommit();

        return $user;
    }
}
