<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthenticatedSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()?->getKey();

        $sessions = $request->user()->tokens()
            ->latest('last_used_at')
            ->latest('created_at')
            ->get()
            ->map(fn ($token): array => [
                'id' => $token->getKey(),
                'name' => $token->name,
                'created_at' => $token->created_at,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'is_current' => $token->getKey() === $currentTokenId,
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function destroy(Request $request, int $token): Response
    {
        $request->user()->tokens()->whereKey($token)->firstOrFail()->delete();

        return response()->noContent();
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()?->getKey();
        $revoked = $request->user()->tokens()->whereKeyNot($currentTokenId)->delete();

        return response()->json(['data' => ['revoked' => $revoked]]);
    }
}
