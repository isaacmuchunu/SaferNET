<?php

namespace App\Actions\Devices;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceLearnerAssignment;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignLearnerToDevice
{
    public function handle(Device $device, Learner $learner, User $actor): DeviceLearnerAssignment
    {
        return DB::transaction(function () use ($device, $learner, $actor): DeviceLearnerAssignment {
            $lockedDevice = Device::query()->lockForUpdate()->findOrFail($device->id);

            if ($learner->institution_id !== $lockedDevice->institution_id) {
                throw ValidationException::withMessages([
                    'learner_id' => 'The learner must belong to the same institution as the device.',
                ]);
            }

            $existing = $lockedDevice->assignments()
                ->where('learner_id', $learner->id)
                ->whereNull('removed_at')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($lockedDevice->activeAssignments()->count() >= 2) {
                throw ValidationException::withMessages([
                    'learner_id' => 'This device has reached the maximum permitted learner attribution of two learners.',
                ]);
            }

            $assignment = $lockedDevice->assignments()->create([
                'institution_id' => $lockedDevice->institution_id,
                'learner_id' => $learner->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            AuditLog::create([
                'actor_id' => $actor->id,
                'institution_id' => $lockedDevice->institution_id,
                'event' => 'device.learner_assigned',
                'auditable_type' => Device::class,
                'auditable_id' => $lockedDevice->id,
                'new_values' => ['learner_id' => $learner->id, 'assignment_id' => $assignment->id],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $assignment;
        });
    }
}
