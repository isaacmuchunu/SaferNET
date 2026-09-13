<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Authentication\PortalSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, PortalSessionService $sessions): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email'))->first();

        if ($user === null
            || $user->hasRole(UserRole::Service)
            || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages(['email' => 'This account is not active.']);
        }

        if ($user->must_change_password && $user->temporary_password_expires_at?->isPast()) {
            throw ValidationException::withMessages([
                'password' => 'This temporary password has expired. Ask the officer who created your account to issue a new one.',
            ]);
        }

        $requirement = $sessions->requirementForLogin($user);

        if ($requirement === 'signed_in') {
            $user->forceFill(['last_login_at' => now()])->save();
        }

        return response()->json([
            'data' => new UserResource($user->loadMissing(['subcounty', 'institution'])),
            'requires' => $requirement,
            'token' => $sessions->issue($user, $request->string('device_name')->toString(), $requirement),
        ]);
    }
}
