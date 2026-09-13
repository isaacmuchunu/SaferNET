<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\ExceptionRequest;
use App\Models\Incident;
use App\Models\Institution;
use App\Models\Learner;
use App\Models\ProtectionComponent;
use App\Models\Subcounty;
use App\Models\User;
use App\Models\WebEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $devices = Device::query()->visibleTo($user);
        $incidents = Incident::query()->visibleTo($user);
        $institutions = Institution::query()->visibleTo($user);
        $components = ProtectionComponent::query()->visibleTo($user);

        $deviceTotal = (clone $devices)->count();
        $unattributed = (clone $devices)->whereDoesntHave('activeAssignments')->count();

        return response()->json(['data' => [
            'subcounties' => Subcounty::query()->visibleTo($user)->count(),
            'institutions' => (clone $institutions)->count(),
            'learners' => Learner::query()->visibleTo($user)->count(),
            'devices' => $deviceTotal,
            'unattributed_devices' => $unattributed,
            'open_incidents' => (clone $incidents)->whereIn('status', ['open', 'under_review'])->count(),

            'attributed_devices' => $deviceTotal - $unattributed,
            'institutions_by_status' => $this->tally($institutions, 'status'),
            'devices_by_status' => $this->tally($devices, 'status'),
            'components_by_health' => $this->componentHealth($components),
            'components_by_type' => $this->componentMatrix($components),
            'components_total' => (clone $components)->count(),
            'open_incidents_by_severity' => $this->tally(
                (clone $incidents)->whereIn('status', ['open', 'under_review']),
                'severity',
            ),
            'pending_approvals' => (clone $institutions)->where('status', 'pending_approval')->count(),
            'pending_exception_requests' => ExceptionRequest::query()->visibleTo($user)->where('status', 'pending')->count(),
            'blocked_requests_today' => $this->blockedToday($user),
            'learner_initiated_blocks_today' => $this->blockedToday($user, learnerInitiated: true),
        ]]);
    }

    /**
     * Count the rows of a visible query grouped by one column.
     *
     * @return array<string, int>
     */
    private function tally(Builder $query, string $column): array
    {
        return $query->reorder()
            ->getQuery()
            ->select($column)
            ->selectRaw('count(*) as aggregate')
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Component health, counted from what is currently true rather than from
     * the column. A component that has stopped checking in reports nothing, so
     * its last claim of health is not evidence it is still enforcing.
     *
     * @return array<string, int>
     */
    private function componentHealth(Builder $components): array
    {
        return (clone $components)->reorder()
            ->getQuery()
            ->select(ProtectionComponent::healthExpression('derived_health'))
            ->selectRaw('count(*) as aggregate')
            ->groupBy('derived_health')
            ->pluck('aggregate', 'derived_health')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Protection component health broken down by component type, for the
     * deployment cards, on the same derived basis.
     *
     * @return array<string, array<string, int>>
     */
    private function componentMatrix(Builder $components): array
    {
        return (clone $components)->reorder()
            ->getQuery()
            ->select('type', ProtectionComponent::healthExpression('derived_health'))
            ->selectRaw('count(*) as aggregate')
            ->groupBy('type', 'derived_health')
            ->get()
            ->groupBy('type')
            ->map(fn ($rows) => $rows->pluck('aggregate', 'derived_health')->map(fn ($count): int => (int) $count)->all())
            ->all();
    }

    private function blockedToday(User $user, bool $learnerInitiated = false): int
    {
        return WebEvent::query()
            ->visibleTo($user)
            ->where('action', 'block')
            ->when($learnerInitiated, fn (Builder $events) => $events->where('request_kind', 'top_level'))
            ->whereDate('occurred_at', today())
            ->count();
    }
}
