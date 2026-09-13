<?php

namespace App\Actions\Sessions;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Learner;
use App\Models\LearnerSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class StartLearnerSession
{
    public function handle(
        Device $device,
        Learner $learner,
        User $actor,
        string $identitySource,
        ?string $pin,
        ?string $ipAddress,
        ?string $userAgent,
    ): LearnerSession {
        return DB::transaction(function () use ($device, $learner, $actor, $identitySource, $pin, $ipAddress, $userAgent): LearnerSession {
            $lockedDevice = Device::query()->lockForUpdate()->findOrFail($device->id);
            $isAssigned = $lockedDevice->activeAssignments()->where('learner_id', $learner->id)->exists();

            if (! $isAssigned) {
                throw ValidationException::withMessages([
                    'learner_id' => 'This learner is not currently assigned to the device.',
                ]);
            }

            if ($identitySource === 'school_pin'
                && ($learner->pin_hash === null || ! Hash::check((string) $pin, $learner->pin_hash))) {
                throw ValidationException::withMessages(['pin' => 'The learner PIN is incorrect.']);
            }

            $lockedDevice->sessions()->whereNull('ended_at')->update([
                'ended_at' => now(),
                'end_reason' => 'superseded',
            ]);

            $session = $lockedDevice->sessions()->create([
                'institution_id' => $lockedDevice->institution_id,
                'learner_id' => $learner->id,
                'started_by' => $actor->id,
                'identity_source' => $identitySource,
                'started_at' => now(),
                'last_activity_at' => now(),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            AuditLog::create([
                'actor_id' => $actor->id,
                'institution_id' => $lockedDevice->institution_id,
                'event' => 'learner_session.started',
                'auditable_type' => LearnerSession::class,
                'auditable_id' => $session->id,
                'new_values' => [
                    'learner_id' => $learner->id,
                    'device_id' => $lockedDevice->id,
                    'identity_source' => $identitySource,
                ],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            return $session;
        });
    }
}
