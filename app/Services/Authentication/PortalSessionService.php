<?php

namespace App\Services\Authentication;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PortalSessionService
{
    public function requirementForLogin(User $user): string
    {
        if ($user->must_change_password) {
            return 'password_change';
        }

        if ($user->mfa_required && $user->mfa_enabled_at === null) {
            return 'mfa_setup';
        }

        if ($user->mfa_required) {
            return 'mfa_challenge';
        }

        return 'signed_in';
    }

    public function requirementForCurrentToken(User $user): string
    {
        $token = $user->currentAccessToken();

        return match (true) {
            $token?->can('portal:access') => 'signed_in',
            $token?->can('onboarding:password') => 'password_change',
            $token?->can('onboarding:mfa') => 'mfa_setup',
            $token?->can('mfa:verify') => 'mfa_challenge',
            default => 'signed_out',
        };
    }

    public function issue(User $user, string $deviceName, string $requirement): string
    {
        $abilities = match ($requirement) {
            'password_change' => ['onboarding:password'],
            'mfa_setup' => ['onboarding:mfa'],
            'mfa_challenge' => ['mfa:verify'],
            default => ['portal:access'],
        };

        // One browser identity owns one live token for this account. A fresh
        // sign-in also invalidates any abandoned MFA or onboarding challenge.
        $user->tokens()->where('name', $deviceName)->delete();

        return $user->createToken($deviceName, $abilities)->plainTextToken;
    }

    public function replaceWith(User $user, string $deviceName, string $requirement): string
    {
        $currentToken = $user->currentAccessToken();

        if ($currentToken instanceof Model) {
            $currentToken->delete();
        } else {
            $user->tokens()->where('name', $deviceName)->delete();
        }

        if ($requirement === 'signed_in') {
            $user->forceFill(['last_login_at' => now()])->save();
        }

        return $this->issue($user, $deviceName, $requirement);
    }
}
