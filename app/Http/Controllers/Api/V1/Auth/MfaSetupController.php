<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConfirmMfaRequest;
use App\Http\Resources\UserResource;
use App\Services\Authentication\PortalSessionService;
use App\Services\Authentication\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MfaSetupController extends Controller
{
    public function store(Request $request, TotpService $totp): JsonResponse
    {
        $user = $request->user();
        $secret = $totp->generateSecret();
        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_enabled_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        return response()->json([
            'data' => [
                'secret' => trim(chunk_split($secret, 4, ' ')),
                'otpauth_uri' => $totp->provisioningUri($secret, $user->email),
                'issuer' => 'SAFERNET',
                'account' => $user->email,
            ],
        ]);
    }

    public function confirm(
        ConfirmMfaRequest $request,
        TotpService $totp,
        PortalSessionService $sessions,
    ): JsonResponse {
        $user = $request->user();

        if (! filled($user->mfa_secret) || ! $totp->verify($user->mfa_secret, $request->string('code')->toString())) {
            throw ValidationException::withMessages(['code' => 'That authenticator code is not valid. Try the current code.']);
        }

        $recoveryCodes = $totp->recoveryCodes();
        $user->forceFill([
            'mfa_enabled_at' => now(),
            'mfa_recovery_codes' => collect($recoveryCodes)
                ->map(fn (string $code): string => hash('sha256', Str::upper($code)))
                ->all(),
        ])->save();

        return response()->json([
            'data' => new UserResource($user->loadMissing(['subcounty', 'institution'])),
            'requires' => 'signed_in',
            'recovery_codes' => $recoveryCodes,
            'token' => $sessions->replaceWith($user, $request->string('device_name')->toString(), 'signed_in'),
        ]);
    }
}
