<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Incident;
use App\Models\Learner;
use App\Models\ProtectionComponent;
use App\Models\WebEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'learners' => Learner::query()->visibleTo($user)->count(),
            'devices' => Device::query()->visibleTo($user)->count(),
            'protected_components' => ProtectionComponent::query()->visibleTo($user)->where('health_status', 'healthy')->count(),
            'blocked_top_level_requests' => WebEvent::query()->visibleTo($user)->where('action', 'block')->where('request_kind', 'top_level')->count(),
            'open_incidents' => Incident::query()->visibleTo($user)->whereIn('status', ['open', 'under_review'])->count(),
            'generated_at' => now(),
        ]]);
    }

    /**
     * Daily incident counts and the leading categories behind them, for the
     * trend chart on the county overview.
     */
    public function incidentTrend(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'days' => ['sometimes', 'integer', 'min:7', 'max:90'],
            'severity' => ['sometimes', 'string', 'in:low,medium,high,critical'],
        ]);

        $user = $request->user();
        $days = (int) ($filters['days'] ?? 30);
        $since = today()->subDays($days - 1);

        $incidents = Incident::query()
            ->visibleTo($user)
            ->where('first_detected_at', '>=', $since)
            ->when($filters['severity'] ?? null, fn ($query, $severity) => $query->where('severity', $severity));

        $counts = (clone $incidents)
            ->reorder()
            ->getQuery()
            ->selectRaw('date(first_detected_at) as day, count(*) as aggregate')
            ->groupBy('day')
            ->pluck('aggregate', 'day')
            ->map(fn ($count): int => (int) $count);

        $series = collect(range(0, $days - 1))->map(function (int $offset) use ($since, $counts): array {
            $date = $since->copy()->addDays($offset)->toDateString();

            return ['date' => $date, 'count' => $counts[$date] ?? 0];
        });

        $categories = (clone $incidents)
            ->reorder()
            ->getQuery()
            ->leftJoin('content_categories', 'incidents.content_category_id', '=', 'content_categories.id')
            ->selectRaw('coalesce(content_categories.name, \'Uncategorised\') as label, count(*) as aggregate')
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'count' => (int) $row->aggregate]);

        return response()->json(['data' => [
            'days' => $days,
            'total' => $series->sum('count'),
            'series' => $series->values(),
            'categories' => $categories->values(),
        ]]);
    }
}
