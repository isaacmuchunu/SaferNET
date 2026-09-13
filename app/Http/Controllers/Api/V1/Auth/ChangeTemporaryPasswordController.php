<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangeTemporaryPasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\Authentication\PortalSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ChangeTemporaryPasswordController extends Controller
{
    public function __invoke(ChangeTemporaryPasswordRequest $request, PortalSessionService $sessions): JsonResponse
    {
        $user = $request->user();

        if (Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Choose a password different from your temporary password.',
            ]);
        }

        $user->forceFill([
            'password' => $request->string('password')->toString(),
            'must_change_password' => false,
            'temporary_password_expires_at' => null,
        ])->save();

        return response()->json([
            'data' => new UserResource($user->loadMissing(['subcounty', 'institution'])),
            'requires' => 'mfa_setup',
            'token' => $sessions->replaceWith($user, $request->string('device_name')->toString(), 'mfa_setup'),
        ]);
    }
}
