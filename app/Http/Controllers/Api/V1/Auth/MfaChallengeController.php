<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VerifyMfaRequest;
use App\Http\Resources\UserResource;
use App\Services\Authentication\PortalSessionService;
use App\Services\Authentication\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class MfaChallengeController extends Controller
{
    public function __invoke(
        VerifyMfaRequest $request,
        TotpService $totp,
        PortalSessionService $sessions,
    ): JsonResponse {
        $user = $request->user();
        $code = $request->string('code')->toString();
        $validTotp = filled($user->mfa_secret) && $totp->verify($user->mfa_secret, $code);
        $remainingRecoveryCodes = $totp->consumeRecoveryCode($user->mfa_recovery_codes ?? [], $code);

        if (! $validTotp && $remainingRecoveryCodes === null) {
            throw ValidationException::withMessages(['code' => 'That MFA or recovery code is not valid.']);
        }

        if (! $validTotp) {
            $user->forceFill(['mfa_recovery_codes' => $remainingRecoveryCodes])->save();
        }

        return response()->json([
            'data' => new UserResource($user->loadMissing(['subcounty', 'institution'])),
            'requires' => 'signed_in',
            'token' => $sessions->replaceWith($user, $request->string('device_name')->toString(), 'signed_in'),
        ]);
    }
}
