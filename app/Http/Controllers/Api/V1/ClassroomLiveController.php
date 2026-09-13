<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EnforcementAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\LearnerSession;
use App\Models\WebEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClassroomLiveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(UserRole::Hoi, UserRole::Clm), 403);

        $institutionId = $user->institution_id;
        $laboratoryId = $request->integer('laboratory_id');
        $this->ensureLaboratoryBelongsToInstitution($laboratoryId ?: null, $institutionId);

        $laboratories = Laboratory::query()
            ->visibleTo($user)
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->orderBy('name')
            ->get(['id', 'institution_id', 'name', 'location']);

        $activeSessions = LearnerSession::query()
            ->visibleTo($user)
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereNull('ended_at')
            ->with(['device.laboratory', 'learner'])
            ->when($laboratoryId, function ($query) use ($laboratoryId) {
                $query->whereHas('device', fn ($dq) => $dq->where('laboratory_id', $laboratoryId));
            })
            ->latest('last_activity_at')
            ->limit(48)
            ->get();

        $sessionIds = $activeSessions->pluck('id')->all();

        // Get latest web event per active session
        $latestEvents = [];
        if (! empty($sessionIds)) {
            $events = WebEvent::query()
                ->whereIn('learner_session_id', $sessionIds)
                ->with('category')
                ->latest('occurred_at')
                ->get();

            foreach ($events as $event) {
                if (! isset($latestEvents[$event->learner_session_id])) {
                    $latestEvents[$event->learner_session_id] = $event;
                }
            }
        }

        $eventCounts = WebEvent::query()
            ->whereIn('learner_session_id', $sessionIds)
            ->select('learner_session_id')
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('SUM(CASE WHEN action = ? THEN 1 ELSE 0 END) AS blocked_count', [EnforcementAction::Block->value])
            ->groupBy('learner_session_id')
            ->get()
            ->keyBy('learner_session_id');

        $tiles = $activeSessions->map(function (LearnerSession $session) use ($latestEvents, $eventCounts, $institutionId) {
            $latestEvent = $latestEvents[$session->id] ?? null;
            $isLocked = (bool) Cache::get("classroom_lock_{$institutionId}_{$session->device?->laboratory_id}", false);

            $action = $latestEvent?->action instanceof EnforcementAction
                ? $latestEvent->action->value
                : (string) ($latestEvent?->action ?? 'allow');

            $status = $isLocked ? 'locked' : ($latestEvent === null ? 'idle' : ($action === 'block' ? 'off_task' : 'on_task'));

            $counts = $eventCounts->get($session->id);
            $totalCount = (int) ($counts?->total_count ?? 0);
            $blockedCount = (int) ($counts?->blocked_count ?? 0);
            $focusScore = $totalCount > 0 ? max(10, (int) round((1 - ($blockedCount / $totalCount)) * 100)) : 100;

            return [
                'session_id' => $session->id,
                'session_uuid' => $session->public_id,
                'learner_id' => $session->learner_id,
                'learner_name' => $session->learner ? ($session->learner->first_name.' '.$session->learner->last_name) : 'Unassigned Pupil',
                'admission_number' => $session->learner?->learner_number ?? 'Not recorded',
                'device_id' => $session->device_id,
                'device_name' => $session->device?->hostname ?? $session->device?->asset_tag ?? 'Terminal-'.$session->device_id,
                'ip_address' => $session->ip_address,
                'laboratory_id' => $session->device?->laboratory_id,
                'laboratory_name' => $session->device?->laboratory?->name ?? 'Computer Lab',
                'active_url' => $latestEvent?->url,
                'active_tab_title' => data_get($latestEvent?->metadata, 'page_title'),
                'domain' => $latestEvent?->domain,
                'category' => $latestEvent?->category?->name ?? 'Unclassified',
                'status' => $status,
                'focus_score' => $focusScore,
                'last_activity_at' => $session->last_activity_at?->toISOString() ?? $session->started_at?->toISOString(),
            ];
        });

        // Current active classroom push/focus broadcast message
        $activeBroadcast = $laboratoryId
            ? Cache::get("classroom_broadcast_{$institutionId}_{$laboratoryId}") ?? Cache::get("classroom_broadcast_{$institutionId}")
            : Cache::get("classroom_broadcast_{$institutionId}");

        return response()->json([
            'data' => [
                'laboratories' => $laboratories,
                'active_count' => $activeSessions->count(),
                'tiles' => $tiles,
                'active_broadcast' => $activeBroadcast,
            ],
        ]);
    }

    public function pushUrl(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(UserRole::Hoi, UserRole::Clm), 403);

        $validated = $request->validate([
            'url' => ['required', 'url:http,https'],
            'title' => ['nullable', 'string', 'max:150'],
            'laboratory_id' => ['nullable', 'integer'],
        ]);

        $institutionId = $user->institution_id;
        $this->ensureLaboratoryBelongsToInstitution($validated['laboratory_id'] ?? null, $institutionId);
        $labKey = $validated['laboratory_id'] ?? '';
        $cacheKey = "classroom_broadcast_{$institutionId}".($labKey ? "_{$labKey}" : '');

        $payload = [
            'command_id' => (string) Str::uuid(),
            'type' => 'CLASSROOM_PUSH_URL',
            'url' => $validated['url'],
            'title' => $validated['title'] ?: 'Teacher Lesson Material',
            'pushed_by' => $user->name,
            'pushed_at' => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes(30));

        AuditLog::create([
            'actor_id' => $user->id,
            'institution_id' => $institutionId,
            'event' => 'classroom.url.pushed',
            'auditable_type' => Laboratory::class,
            'auditable_id' => $validated['laboratory_id'] ?? 0,
            'old_values' => null,
            'new_values' => $payload,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Lesson URL broadcasted to student workstations.',
            'broadcast' => $payload,
            'data' => [
                'pushed' => true,
                'url' => $payload['url'],
                'broadcast' => $payload,
            ],
        ]);
    }

    public function nudge(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(UserRole::Hoi, UserRole::Clm), 403);

        $validated = $request->validate([
            'laboratory_id' => ['nullable', 'integer'],
            'message' => ['nullable', 'string', 'max:200'],
        ]);

        $institutionId = $user->institution_id;
        $this->ensureLaboratoryBelongsToInstitution($validated['laboratory_id'] ?? null, $institutionId);
        $labKey = $validated['laboratory_id'] ?? '';
        $cacheKey = "classroom_broadcast_{$institutionId}".($labKey ? "_{$labKey}" : '');

        $payload = [
            'command_id' => (string) Str::uuid(),
            'type' => 'CLASSROOM_ATTENTION_NUDGE',
            'title' => $validated['message'] ?: 'Teacher Notice: Please focus on the classroom lesson.',
            'pushed_by' => $user->name,
            'pushed_at' => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes(5));

        AuditLog::create([
            'actor_id' => $user->id,
            'institution_id' => $institutionId,
            'event' => 'classroom.attention_nudge.sent',
            'auditable_type' => Laboratory::class,
            'auditable_id' => $validated['laboratory_id'] ?? 0,
            'old_values' => null,
            'new_values' => $payload,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Attention nudge sent to classroom screens.',
            'broadcast' => $payload,
        ]);
    }

    public function focusMode(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(UserRole::Hoi, UserRole::Clm), 403);

        $validated = $request->validate([
            'laboratory_id' => ['required', 'integer'],
            'locked' => ['required', 'boolean'],
        ]);

        $institutionId = $user->institution_id;
        $this->ensureLaboratoryBelongsToInstitution($validated['laboratory_id'], $institutionId);
        $cacheKey = "classroom_lock_{$institutionId}_{$validated['laboratory_id']}";

        Cache::put($cacheKey, $validated['locked'], now()->addHours(3));

        AuditLog::create([
            'actor_id' => $user->id,
            'institution_id' => $institutionId,
            'event' => $validated['locked'] ? 'classroom.focus_mode.locked' : 'classroom.focus_mode.released',
            'auditable_type' => Laboratory::class,
            'auditable_id' => $validated['laboratory_id'],
            'old_values' => null,
            'new_values' => ['locked' => $validated['locked']],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => $validated['locked'] ? 'Focus Mode activated. Student screens restricted.' : 'Focus Mode deactivated.',
            'locked' => $validated['locked'],
        ]);
    }

    private function ensureLaboratoryBelongsToInstitution(?int $laboratoryId, ?int $institutionId): void
    {
        if ($laboratoryId === null) {
            return;
        }

        if ($institutionId === null || ! Laboratory::query()
            ->where('institution_id', $institutionId)
            ->whereKey($laboratoryId)
            ->exists()) {
            throw ValidationException::withMessages([
                'laboratory_id' => 'The laboratory is not registered to your school.',
            ]);
        }
    }
}
