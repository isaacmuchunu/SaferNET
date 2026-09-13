<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Devices\AssignLearnerToDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AssignLearnerToDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceLearnerAssignment;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeviceAssignmentController extends Controller
{
    public function store(
        AssignLearnerToDeviceRequest $request,
        Device $device,
        AssignLearnerToDevice $assignLearner,
    ): DeviceResource {
        $learner = Learner::query()->visibleTo($request->user())->findOrFail($request->integer('learner_id'));
        $assignLearner->handle($device, $learner, $request->user());

        return new DeviceResource($device->fresh()->load(['activeAssignments.learner.learnerGroup', 'activeSessions.learner']));
    }

    public function destroy(Request $request, Device $device, DeviceLearnerAssignment $assignment): JsonResponse
    {
        $user = $request->user();
        Gate::authorize('assignLearner', $device);

        if ($assignment->device_id !== $device->id) {
            abort(404);
        }

        if ($assignment->removed_at === null) {
            $assignment->update([
                'removed_by' => $user->id,
                'removed_at' => now(),
                'removal_reason' => $request->string('reason')->limit(255)->toString() ?: 'administrative_reassignment',
            ]);

            AuditLog::create([
                'actor_id' => $user->id,
                'institution_id' => $device->institution_id,
                'event' => 'device.learner_unassigned',
                'auditable_type' => Device::class,
                'auditable_id' => $device->id,
                'old_values' => ['learner_id' => $assignment->learner_id, 'assignment_id' => $assignment->id],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json(['message' => 'Learner assignment removed.']);
    }
}
