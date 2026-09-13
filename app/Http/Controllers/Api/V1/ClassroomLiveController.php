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
use Illuminate\Support\Collection;
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
            ->limit((int) config('classroom.tile_limit'))
            ->get();

        $sessionIds = $activeSessions->pluck('id')->all();
        $latestEvents = $this->latestEventPerSession($sessionIds);
        $eventCounts = $this->recentEventCounts($sessionIds);

        $reportingSince = now()->subSeconds((int) config('classroom.reporting_within_seconds'));

        $tiles = $activeSessions->map(function (LearnerSession $session) use ($latestEvents, $eventCounts, $institutionId, $reportingSince) {
            $latestEvent = $latestEvents->get($session->id);
            $isLocked = self::focusState($institutionId, $session->device?->laboratory_id)['locked'];

            // Whether the workstation is reporting *now*, judged on the server so
            // every tile is measured against one clock.
            $lastActivityAt = $session->last_activity_at ?? $session->started_at;
            $isReporting = $lastActivityAt !== null && $lastActivityAt->greaterThanOrEqualTo($reportingSince);

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
                'is_reporting' => $isReporting,
                'events_in_window' => $totalCount,
                'blocked_in_window' => $blockedCount,
                // When this learner's session began, and how long it has been
                // live — the question a teacher actually asks of a lab.
                'session_started_at' => $session->started_at?->toISOString(),
                'live_for_seconds' => $session->started_at === null ? null : (int) $session->started_at->diffInSeconds(now()),
                'last_activity_at' => $lastActivityAt?->toISOString(),
            ];
        });

        $pending = self::pendingCommands($institutionId, $laboratoryId ?: null);
        $activeBroadcast = $pending[0] ?? null;

        return response()->json([
            'data' => [
                'laboratories' => $laboratories,
                'active_count' => $activeSessions->count(),
                'tiles' => $tiles,
                'active_broadcast' => $activeBroadcast,
                'pending_commands' => $pending,
                'focus' => self::focusState($institutionId, $laboratoryId ?: null),
            ],
        ]);
    }

    /**
     * The newest event of each active session, as one row per session.
     *
     * Read volume here must track the number of tiles on screen, not how long
     * the lesson has been running: this page polls every few seconds, and
     * pulling a session's whole history to keep its last row does not stay
     * affordable through a double period.
     *
     * @param  list<int>  $sessionIds
     * @return Collection<int, WebEvent>
     */
    private function latestEventPerSession(array $sessionIds): Collection
    {
        if ($sessionIds === []) {
            return collect();
        }

        return WebEvent::query()
            ->with('category')
            ->whereIn('id', function ($query) use ($sessionIds): void {
                // DISTINCT ON is PostgreSQL's index-ordered "first row per
                // group", which this project already requires.
                $query->selectRaw('DISTINCT ON (learner_session_id) id')
                    ->from('web_events')
                    ->whereIn('learner_session_id', $sessionIds)
                    ->orderByRaw('learner_session_id, occurred_at DESC, id DESC');
            })
            ->get()
            ->keyBy('learner_session_id');
    }

    /**
     * Activity counts over a bounded recent window.
     *
     * A focus score for a live monitor describes the lesson happening now. An
     * all-time count both drifts — one bad morning suppressing the score all
     * term — and grows without limit, so it is scoped to a window.
     *
     * @param  list<int>  $sessionIds
     * @return Collection<int, object>
     */
    private function recentEventCounts(array $sessionIds): Collection
    {
        if ($sessionIds === []) {
            return collect();
        }

        $since = now()->subMinutes((int) config('classroom.focus_window_minutes'));

        return WebEvent::query()
            ->whereIn('learner_session_id', $sessionIds)
            ->where('occurred_at', '>=', $since)
            ->select('learner_session_id')
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('SUM(CASE WHEN action = ? THEN 1 ELSE 0 END) AS blocked_count', [EnforcementAction::Block->value])
            ->groupBy('learner_session_id')
            ->get()
            ->keyBy('learner_session_id');
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
        $cacheKey = self::commandKey('push', $institutionId, $validated['laboratory_id'] ?? null);

        $payload = [
            'command_id' => (string) Str::uuid(),
            'type' => 'CLASSROOM_PUSH_URL',
            'url' => $validated['url'],
            'title' => ($validated['title'] ?? null) ?: 'Teacher Lesson Material',
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
        // A nudge has its own slot: replacing a lesson URL a learner has not yet
        // polled for would silently drop the teacher's actual instruction.
        $cacheKey = self::commandKey('nudge', $institutionId, $validated['laboratory_id'] ?? null);

        $payload = [
            'command_id' => (string) Str::uuid(),
            'type' => 'CLASSROOM_ATTENTION_NUDGE',
            'title' => ($validated['message'] ?? null) ?: 'Teacher Notice: Please focus on the classroom lesson.',
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

    /**
     * Set or release focus mode for a laboratory.
     *
     * Focus is explicit state, not an inference from whatever command happens
     * to be cached. A lock carries the origin it confines learners to, its own
     * revision and an expiry, so locking without a target is refused here
     * rather than producing a classroom that reads as locked while restricting
     * nothing.
     */
    public function focusMode(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(UserRole::Hoi, UserRole::Clm), 403);

        $validated = $request->validate([
            'laboratory_id' => ['required', 'integer'],
            'locked' => ['required', 'boolean'],
            'url' => ['nullable', 'required_if_accepted:locked', 'url:http,https'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
        ]);

        $institutionId = $user->institution_id;
        $this->ensureLaboratoryBelongsToInstitution($validated['laboratory_id'], $institutionId);

        $state = $this->putFocusState(
            $institutionId,
            $validated['laboratory_id'],
            $validated['locked'],
            $validated['url'] ?? null,
            $validated['minutes'] ?? null,
        );

        AuditLog::create([
            'actor_id' => $user->id,
            'institution_id' => $institutionId,
            'event' => $validated['locked'] ? 'classroom.focus_mode.locked' : 'classroom.focus_mode.released',
            'auditable_type' => Laboratory::class,
            'auditable_id' => $validated['laboratory_id'],
            'old_values' => null,
            'new_values' => $state,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => $validated['locked'] ? 'Focus Mode activated. Student screens restricted.' : 'Focus Mode deactivated.',
            'locked' => $state['locked'],
            'focus' => $state,
        ]);
    }

    /**
     * Writes focus state to its own key, so it survives a nudge or a later URL
     * push landing in the single command slot.
     *
     * @return array{locked: bool, url: ?string, revision: int, expires_at: ?string}
     */
    private function putFocusState(int $institutionId, int $laboratoryId, bool $locked, ?string $url, ?int $minutes): array
    {
        $key = self::focusKey($institutionId, $laboratoryId);
        $previous = Cache::get($key);
        $expiresAt = $locked ? now()->addMinutes($minutes ?? 180) : null;

        $state = [
            'locked' => $locked,
            'url' => $locked ? $url : null,
            // Monotonic, so a client can tell a re-lock from the lock it already has.
            'revision' => (int) ($previous['revision'] ?? 0) + 1,
            'expires_at' => $expiresAt?->toIso8601String(),
        ];

        if ($locked) {
            Cache::put($key, $state, $expiresAt);
        } else {
            // The released state is kept briefly so a polling client learns that
            // the lock ended, rather than inferring it from a cache miss.
            Cache::put($key, $state, now()->addMinutes(15));
        }

        return $state;
    }

    /**
     * The focus state a client should currently obey. An expired lock is not a
     * lock, and is reported as released rather than as nothing.
     *
     * @return array{locked: bool, url: ?string, revision: int, expires_at: ?string}
     */
    public static function focusState(int $institutionId, ?int $laboratoryId): array
    {
        $released = ['locked' => false, 'url' => null, 'revision' => 0, 'expires_at' => null];

        if ($laboratoryId === null) {
            return $released;
        }

        $state = Cache::get(self::focusKey($institutionId, $laboratoryId));

        if (! is_array($state) || ! ($state['locked'] ?? false)) {
            return [...$released, 'revision' => (int) ($state['revision'] ?? 0)];
        }

        // A lock with no target restricts nothing, so it is not honoured as one.
        if (($state['url'] ?? null) === null) {
            return [...$released, 'revision' => (int) $state['revision']];
        }

        if ($state['expires_at'] !== null && now()->greaterThan($state['expires_at'])) {
            return [...$released, 'revision' => (int) $state['revision']];
        }

        return $state;
    }

    private static function focusKey(int $institutionId, int $laboratoryId): string
    {
        return "classroom_focus_{$institutionId}_{$laboratoryId}";
    }

    /**
     * Each command kind gets its own slot, per school and per laboratory. One
     * shared slot meant the newest command evicted whatever a client had not
     * polled for yet.
     */
    private static function commandKey(string $kind, int $institutionId, ?int $laboratoryId): string
    {
        return "classroom_{$kind}_{$institutionId}".($laboratoryId ? "_{$laboratoryId}" : '');
    }

    /**
     * Every command still waiting to be collected, newest first. A laboratory
     * sees its own commands as well as those addressed to the whole school.
     *
     * @return list<array<string, mixed>>
     */
    public static function pendingCommands(int $institutionId, ?int $laboratoryId): array
    {
        $commands = [];

        foreach (['push', 'nudge'] as $kind) {
            // A laboratory's own command outranks the school-wide one of the
            // same kind, so the broader slot is only read when it has none.
            foreach ([$laboratoryId, null] as $scope) {
                $command = Cache::get(self::commandKey($kind, $institutionId, $scope));

                if (is_array($command)) {
                    $commands[] = $command;

                    break;
                }
            }
        }

        usort($commands, fn (array $a, array $b): int => strcmp($b['pushed_at'] ?? '', $a['pushed_at'] ?? ''));

        return $commands;
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
