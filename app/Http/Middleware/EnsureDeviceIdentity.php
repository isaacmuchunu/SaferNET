<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\Incident;
use App\Models\LearnerSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceIdentity
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->hasRole(UserRole::Service) && $user->managed_device_id !== null, 403, 'A device-bound service token is required.');

        $device = Device::withoutGlobalScopes()->where('institution_id', $user->institution_id)
            ->find($user->managed_device_id);
        abort_unless($device !== null && $device->status !== 'retired', 403, 'The enrolled device is unavailable.');

        if ($request->filled('device_id')) {
            abort_unless((string) $request->input('device_id') === (string) $device->id, 403);
        }

        if ($request->filled('workstation_id')) {
            abort_unless(in_array($request->input('workstation_id'), [$device->asset_tag, $device->hostname], true), 403);
        }

        if ($request->filled('laboratory_id')) {
            abort_unless((string) $request->input('laboratory_id') === (string) $device->laboratory_id, 403);
        }

        if ($request->filled('learner_session_id')) {
            abort_unless(LearnerSession::withoutGlobalScopes()->where('institution_id', $device->institution_id)
                ->where('device_id', $device->id)->whereKey($request->input('learner_session_id'))->exists(), 404);
        }

        if ($request->filled('incident_id')) {
            abort_unless(Incident::withoutGlobalScopes()->where('institution_id', $device->institution_id)
                ->where('device_id', $device->id)->whereKey($request->input('incident_id'))->exists(), 404);
        }

        if ($request->filled('learner_id')) {
            $learnerId = $request->input('learner_id');
            abort_unless($device->activeAssignments()->where('learner_id', $learnerId)->exists()
                || $device->sessions()->where('learner_id', $learnerId)->exists(), 404);
        }

        if ($request->routeIs('api.v1.protection-components.store')) {
            abort_unless(in_array($request->input('identifier'), [$device->asset_tag, $device->hostname, $device->public_id], true), 403);
        }

        if ($request->routeIs('api.v1.agent.resolve-device')) {
            abort_unless(in_array($request->query('identifier'), [$device->asset_tag, $device->hostname, $device->serial_number, $device->public_id], true), 404);
        }

        $request->attributes->set('managed_device', $device);
        $request->merge([
            'device_id' => $device->id,
            'workstation_id' => $device->asset_tag,
            'laboratory_id' => $device->laboratory_id,
        ]);

        return $next($request);
    }
}
