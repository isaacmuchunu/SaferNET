<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\ExceptionRequest;
use App\Models\Institution;
use App\Models\Laboratory;
use App\Models\LearnerGroup;
use App\Models\LearnerSession;
use App\Models\ProtectionComponent;
use App\Services\Filtering\AgentPolicyCompiler;
use App\Services\Filtering\BrowserPolicyCompiler;
use App\Services\Filtering\EffectivePolicy;
use App\Services\Filtering\EffectivePolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ExtensionController extends Controller
{
    public function __construct(
        private readonly EffectivePolicyResolver $resolver,
        private readonly BrowserPolicyCompiler $browser,
        private readonly AgentPolicyCompiler $agent,
    ) {}

    /**
     * Deliver the school's effective policy to a browser extension.
     *
     * The browser can only hold so many dynamic rules, so the delivery may be a
     * subset of the policy. `delivery.complete` says whether it is, and the
     * extension must not treat a truncated ruleset as the whole policy.
     */
    public function sync(Request $request): JsonResponse
    {
        $institution = $this->institutionFor($request);
        $policy = $this->resolver->resolve($institution, $this->learnerGroupFor($request, $institution));
        $delivery = $this->browser->compile($policy);

        return response()->json([
            ...$this->policyEnvelope($institution, $policy),
            'blocked_domains' => $delivery['blocked_domains'],
            'allowed_domains' => $delivery['allowed_domains'],
            'dnr_rules' => $delivery['dnr_rules'],
            'delivery' => [
                'client' => 'browser_extension',
                'total_domains' => $delivery['total_domains'],
                'installed_domains' => $delivery['installed_domains'],
                'complete' => $delivery['complete'],
            ],
        ]);
    }

    /**
     * Deliver the same effective policy to the Windows agent, which keeps a
     * hash set rather than a browser ruleset and so receives it complete.
     */
    public function agentPolicy(Request $request): JsonResponse
    {
        $institution = $this->institutionFor($request);
        $policy = $this->resolver->resolve($institution, $this->learnerGroupFor($request, $institution));
        $delivery = $this->agent->compile($policy);

        return response()->json([
            ...$this->policyEnvelope($institution, $policy),
            'blocked_domains' => $delivery['blocked_domains'],
            'allowed_domains' => $delivery['allowed_domains'],
            'delivery' => [
                'client' => 'endpoint_agent',
                'total_domains' => $delivery['total_domains'],
                'installed_domains' => $delivery['installed_domains'],
                'complete' => $delivery['complete'],
            ],
        ]);
    }

    /**
     * The fields both clients receive: what the school's policy resolves to,
     * rather than what any one client can install.
     *
     * @return array<string, mixed>
     */
    private function policyEnvelope(Institution $institution, EffectivePolicy $policy): array
    {
        return [
            'revision' => $policy->revision,
            'policy_version' => $policy->policyVersion,
            'content_hash' => $policy->contentHash,
            'institution_id' => $institution->id,
            'institution' => $institution->name,
            'nemis_code' => $institution->nemis_code,
            'learner_group_id' => $policy->learnerGroupId,
            'policy_name' => $policy->policyName,
            'enforce_safesearch' => true,
            'enforce_youtube_strict' => true,
            'blocked_categories' => $policy->blockedCategoryNames(),
            'categories' => $policy->categories,
            'synced_at' => $policy->compiledAt->toISOString(),
        ];
    }

    /** Resolve an optional learner group, refusing one from another school. */
    private function learnerGroupFor(Request $request, Institution $institution): ?int
    {
        $validated = $request->validate([
            'learner_group_id' => ['nullable', 'integer'],
        ]);

        $learnerGroupId = $validated['learner_group_id'] ?? null;

        if ($learnerGroupId !== null && ! LearnerGroup::query()
            ->withoutGlobalScopes()
            ->where('institution_id', $institution->id)
            ->whereKey($learnerGroupId)
            ->exists()) {
            throw ValidationException::withMessages([
                'learner_group_id' => 'The learner group is not registered to this school.',
            ]);
        }

        return $learnerGroupId;
    }

    /**
     * Record extension health without accepting a caller-selected tenant.
     *
     * A heartbeat proves contact and nothing more, so it no longer asserts that
     * a policy was installed. `policy_synced_at` is the client's own last
     * successful sync — absent when every sync has failed — and the status is
     * derived from that rather than taken on trust.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $institution = $this->institutionFor($request);
        $validated = $request->validate([
            'workstation_id' => ['required', 'string', 'max:100'],
            'device_id' => ['nullable', 'integer'],
            'version' => ['nullable', 'string', 'max:20'],
            'rules_count' => ['nullable', 'integer', 'min:0'],
            'last_synced_at' => ['nullable', 'date'],
            'applied_revision' => ['nullable', 'integer'],
            'policy_complete' => ['nullable', 'boolean'],
            'last_error' => ['nullable', 'string', 'max:500'],
        ]);

        $device = null;
        if (isset($validated['device_id'])) {
            $device = Device::query()
                ->where('institution_id', $institution->id)
                ->find($validated['device_id']);

            if ($device === null) {
                throw ValidationException::withMessages([
                    'device_id' => 'The device is not registered to this school.',
                ]);
            }
        }

        // Clients report in UTC and these columns carry no timezone, so the
        // value is converted before it is stored or compared against now().
        $lastSyncedAt = isset($validated['last_synced_at'])
            ? Carbon::parse($validated['last_synced_at'])->setTimezone(config('app.timezone'))
            : null;

        $component = ProtectionComponent::query()->updateOrCreate(
            [
                'institution_id' => $institution->id,
                'type' => 'browser_extension',
                'identifier' => $validated['workstation_id'],
            ],
            [
                'device_id' => $device?->id,
                'version' => $validated['version'] ?? '2.5.0',
                'health_status' => $this->healthFromReport($validated, $lastSyncedAt),
                'last_seen_at' => now(),
                'policy_synced_at' => $lastSyncedAt,
                'metadata' => [
                    'rules_count' => $validated['rules_count'] ?? 0,
                    'applied_revision' => $validated['applied_revision'] ?? null,
                    'policy_complete' => $validated['policy_complete'] ?? null,
                    'last_error' => $validated['last_error'] ?? null,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ],
        );

        $device?->update(['last_seen_at' => now(), 'status' => 'active']);

        return response()->json([
            'status' => 'acknowledged',
            'component_id' => $component->id,
            'health_status' => $component->effectiveHealthStatus(),
            'time' => now()->toISOString(),
        ]);
    }

    /**
     * What a heartbeat actually establishes about enforcement.
     *
     * A client that has never completed a sync installs nothing, so it is
     * degraded however punctually it reports in; one carrying an error, or
     * whose last successful sync has aged out, is degraded too.
     *
     * @param  array<string, mixed>  $report
     */
    private function healthFromReport(array $report, ?Carbon $lastSyncedAt): string
    {
        if ($lastSyncedAt === null) {
            return ProtectionComponent::Degraded;
        }

        if (($report['last_error'] ?? null) !== null) {
            return ProtectionComponent::Degraded;
        }

        if ($lastSyncedAt->lt(now()->subMinutes((int) config('deployment.policy_stale_after_minutes')))) {
            return ProtectionComponent::Degraded;
        }

        return ProtectionComponent::Healthy;
    }

    /**
     * Resolve the learner session currently open on a workstation.
     *
     * Learner attribution must not depend on someone copying a session
     * identifier into an options page. The client asks which learner is signed
     * in at this device and re-asks as it polls, so a learner change is picked
     * up on its own rather than silently mis-attributing the next hour of
     * browsing.
     */
    public function session(Request $request): JsonResponse
    {
        $institution = $this->institutionFor($request);
        $validated = $request->validate([
            'device_id' => ['nullable', 'integer'],
            'workstation_id' => ['nullable', 'string', 'max:100'],
        ]);

        $device = $this->deviceForWorkstation($institution, $validated);

        if ($device === null) {
            return response()->json([
                'session' => null,
                'reason' => 'no_device',
                'message' => 'This workstation is not enrolled as a device for the school.',
                'checked_at' => now()->toISOString(),
            ]);
        }

        $session = LearnerSession::query()
            ->withoutGlobalScopes()
            ->where('institution_id', $institution->id)
            ->where('device_id', $device->id)
            ->whereNull('ended_at')
            ->with('learner')
            ->latest('started_at')
            ->first();

        return response()->json([
            'session' => $session === null ? null : [
                'id' => $session->id,
                'uuid' => $session->public_id,
                'learner_id' => $session->learner_id,
                'learner_name' => $session->learner === null
                    ? null
                    : trim($session->learner->first_name.' '.$session->learner->last_name),
                'started_at' => $session->started_at?->toISOString(),
            ],
            'reason' => $session === null ? 'no_open_session' : null,
            'device_id' => $device->id,
            'checked_at' => now()->toISOString(),
        ]);
    }

    /**
     * The device a client is running on, by registered id or by the asset tag
     * or hostname its workstation identifier matches.
     *
     * @param  array<string, mixed>  $validated
     */
    private function deviceForWorkstation(Institution $institution, array $validated): ?Device
    {
        $devices = fn () => Device::query()->where('institution_id', $institution->id);

        if (isset($validated['device_id'])) {
            return $devices()->find($validated['device_id']);
        }

        $workstation = $validated['workstation_id'] ?? null;

        if ($workstation === null) {
            return null;
        }

        return $devices()
            ->where(fn ($query) => $query
                ->where('asset_tag', $workstation)
                ->orWhere('hostname', $workstation))
            ->first();
    }

    /** Return the latest school or laboratory command for extension polling. */
    public function commands(Request $request): JsonResponse
    {
        $institution = $this->institutionFor($request);
        $validated = $request->validate([
            'laboratory_id' => ['nullable', 'integer'],
        ]);
        $laboratoryId = $validated['laboratory_id'] ?? null;

        if ($laboratoryId !== null && ! Laboratory::query()
            ->where('institution_id', $institution->id)
            ->whereKey($laboratoryId)
            ->exists()) {
            throw ValidationException::withMessages([
                'laboratory_id' => 'The laboratory is not registered to this school.',
            ]);
        }

        $commands = ClassroomLiveController::pendingCommands($institution->id, $laboratoryId);
        $focus = ClassroomLiveController::focusState($institution->id, $laboratoryId);

        return response()->json([
            // `command` is the newest one, kept for clients that read a single
            // slot; `commands` carries every kind still waiting, so a nudge no
            // longer hides a lesson URL the learner has not collected.
            'command' => $commands[0] ?? null,
            'commands' => $commands,
            // Focus is state the client obeys directly, not something it has to
            // infer from a cached URL command that may long since have expired.
            'focus' => $focus,
            'focus_locked' => $focus['locked'],
            'checked_at' => now()->toISOString(),
        ]);
    }

    /** Submit a learner curriculum exception from a managed workstation. */
    public function storeExceptionRequest(Request $request): JsonResponse
    {
        $institution = $this->institutionFor($request);
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'reason' => ['required', 'string', 'max:2000'],
            'workstation_id' => ['nullable', 'string', 'max:100'],
        ]);

        $exceptionRequest = ExceptionRequest::query()->create([
            'institution_id' => $institution->id,
            'requested_by' => $request->user()->id,
            'domain' => mb_strtolower(trim($validated['domain'])),
            'reason' => $validated['reason'],
            'status' => 'pending',
        ]);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'institution_id' => $institution->id,
            'event' => 'extension.exception_request.submitted',
            'auditable_type' => ExceptionRequest::class,
            'auditable_id' => $exceptionRequest->id,
            'old_values' => null,
            'new_values' => [
                'domain' => $exceptionRequest->domain,
                'workstation_id' => $validated['workstation_id'] ?? null,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'The curriculum access request was submitted for school review.',
            'request_id' => $exceptionRequest->id,
        ], 201);
    }

    private function institutionFor(Request $request): Institution
    {
        $user = $request->user();
        abort_unless($user?->hasRole(UserRole::Service) && $user->institution_id !== null, 403);

        return Institution::query()->findOrFail($user->institution_id);
    }
}
