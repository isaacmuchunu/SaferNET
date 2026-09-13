<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Authentication\PortalSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthenticationContextController extends Controller
{
    public function __invoke(Request $request, PortalSessionService $sessions): JsonResponse
    {
        $user = $request->user()->loadMissing(['subcounty', 'institution']);
        $requirement = $sessions->requirementForCurrentToken($user);
        abort_if($requirement === 'signed_out', 403, 'This session cannot access the SAFERNET portal.');

        return response()->json([
            'data' => new UserResource($user),
            'requires' => $requirement,
        ]);
    }
}
