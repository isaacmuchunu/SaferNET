<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sessions\StartLearnerSession;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Institution;
use App\Models\Learner;
use App\Models\LearnerSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Signs a learner in at the workstation they are sitting at.
 *
 * Until now a session could only be opened by an officer in the portal, which
 * meant a lab of forty learners changing every period depended on somebody
 * remembering. Stale attribution is not a cosmetic problem: it records one
 * child's browsing against another's name.
 *
 * This endpoint is reachable by anyone holding the workstation's service token,
 * so it is written as though the caller is hostile:
 *
 *   · a learner PIN is short, so attempts are rate limited on two axes — see
 *     the `workstation-signin` limiter;
 *   · every failure returns the same message, so the endpoint cannot be used to
 *     discover which learners exist or which are assigned to a device;
 *   · the learner must already be assigned to that device, which is enforced
 *     inside the action rather than here;
 *   · failures are audited with the real reason, so a grind is visible to an
 *     officer even though the attacker learns nothing.
 *
 * The PIN is never logged, never stored, and never echoed back.
 */
class WorkstationSessionController extends Controller
{
    private const GenericFailure = 'That learner number and PIN did not match a learner assigned to this workstation.';

    public function signIn(Request $request, StartLearnerSession $startSession): JsonResponse
    {
        $institution = $this->institutionFor($request);

        $validated = $request->validate([
            'workstation_id' => ['required_without:device_id', 'nullable', 'string', 'max:100'],
            'device_id' => ['required_without:workstation_id', 'nullable', 'integer'],
            'learner_number' => ['required', 'string', 'max:100'],
            'pin' => ['required', 'string', 'max:20'],
        ]);

        $device = $this->deviceFor($institution, $validated);
        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->where('institution_id', $institution->id)
            ->where('learner_number', $validated['learner_number'])
            ->where('status', 'active')
            ->first();

        if ($device === null || $learner === null) {
            $this->recordFailure($request, $institution->id, $device?->id, $validated['learner_number'], $device === null ? 'unknown_device' : 'unknown_learner');

            throw ValidationException::withMessages(['pin' => self::GenericFailure]);
        }

        try {
            $session = $startSession->handle(
                $device,
                $learner,
                $request->user(),
                'school_pin',
                $validated['pin'],
                $request->ip(),
                $request->userAgent(),
            );
        } catch (ValidationException $exception) {
            // The action distinguishes "not assigned to this device" from "wrong
            // PIN". A learner at the keyboard must not learn which, or the
            // endpoint answers questions about other children.
            $this->recordFailure(
                $request,
                $institution->id,
                $device->id,
                $validated['learner_number'],
                array_key_first($exception->errors()) === 'pin' ? 'wrong_pin' : 'not_assigned',
            );

            throw ValidationException::withMessages(['pin' => self::GenericFailure]);
        }

        return response()->json([
            'session' => [
                'id' => $session->id,
                'uuid' => $session->public_id,
                'learner_id' => $learner->id,
                'learner_name' => trim($learner->first_name.' '.$learner->last_name),
                'started_at' => $session->started_at?->toISOString(),
            ],
            'device_id' => $device->id,
        ], 201);
    }

    /**
     * Ends the session open on this workstation.
     *
     * No PIN: a learner must always be able to sign themselves out, and the
     * alternative — a session left open because someone forgot their PIN —
     * attributes the next learner's browsing to them.
     */
    public function signOut(Request $request): JsonResponse
    {
        $institution = $this->institutionFor($request);

        $validated = $request->validate([
            'workstation_id' => ['required_without:device_id', 'nullable', 'string', 'max:100'],
            'device_id' => ['required_without:workstation_id', 'nullable', 'integer'],
        ]);

        $device = $this->deviceFor($institution, $validated);

        if ($device === null) {
            return response()->json(['ended' => 0]);
        }

        $ended = LearnerSession::query()
            ->withoutGlobalScopes()
            ->where('institution_id', $institution->id)
            ->where('device_id', $device->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now(), 'end_reason' => 'sign_out']);

        if ($ended > 0) {
            AuditLog::create([
                'actor_id' => $request->user()->id,
                'institution_id' => $institution->id,
                'event' => 'learner_session.signed_out',
                'auditable_type' => Device::class,
                'auditable_id' => $device->id,
                'old_values' => null,
                'new_values' => ['device_id' => $device->id, 'sessions_ended' => $ended],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json(['ended' => $ended]);
    }

    /**
     * Records an attempt that failed, with the reason the caller is not told.
     *
     * A run of these against one device or one learner is what a brute force
     * looks like from the outside, and an officer should be able to see it.
     *
     * @param  array<string, mixed>  $validated
     */
    private function recordFailure(Request $request, int $institutionId, ?int $deviceId, string $learnerNumber, string $reason): void
    {
        AuditLog::create([
            'actor_id' => $request->user()->id,
            'institution_id' => $institutionId,
            'event' => 'learner_session.sign_in_failed',
            'auditable_type' => Device::class,
            'auditable_id' => $deviceId ?? 0,
            'old_values' => null,
            // The learner number is an identifier, not a secret. The PIN is
            // never recorded anywhere, including here.
            'new_values' => ['learner_number' => $learnerNumber, 'reason' => $reason],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function deviceFor(Institution $institution, array $validated): ?Device
    {
        $devices = Device::query()->withoutGlobalScopes()->where('institution_id', $institution->id);

        if (! empty($validated['device_id'])) {
            return $devices->find($validated['device_id']);
        }

        $workstation = $validated['workstation_id'] ?? null;

        return $workstation === null
            ? null
            : $devices->where(fn ($query) => $query->where('asset_tag', $workstation)->orWhere('hostname', $workstation))->first();
    }

    private function institutionFor(Request $request): Institution
    {
        $user = $request->user();
        abort_unless($user?->hasRole(UserRole::Service) && $user->institution_id !== null, 403);

        return Institution::query()->withoutGlobalScopes()->findOrFail($user->institution_id);
    }
}
