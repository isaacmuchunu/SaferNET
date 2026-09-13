<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sessions\StartLearnerSession;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StartLearnerSessionRequest;
use App\Http\Resources\LearnerSessionResource;
use App\Models\Device;
use App\Models\Learner;
use App\Models\LearnerSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LearnerSessionController extends Controller
{
    public function store(
        StartLearnerSessionRequest $request,
        Device $device,
        StartLearnerSession $startSession,
    ): LearnerSessionResource {
        $learner = Learner::query()->visibleTo($request->user())->findOrFail($request->integer('learner_id'));
        $session = $startSession->handle(
            $device,
            $learner,
            $request->user(),
            $request->string('identity_source')->toString(),
            $request->input('pin'),
            $request->ip(),
            $request->userAgent(),
        );

        return new LearnerSessionResource($session->load(['device', 'learner.learnerGroup']));
    }

    public function destroy(Request $request, LearnerSession $learnerSession): JsonResponse
    {
        abort_unless($request->user()->hasRole(UserRole::Cde, UserRole::Hoi, UserRole::Clm), 403);

        $learnerSession->update(['ended_at' => now(), 'end_reason' => 'signed_out']);

        return response()->json(['message' => 'Learner session ended.']);
    }
}
