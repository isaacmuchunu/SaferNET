<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EnforcementAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlockedDomain;
use App\Models\Device;
use App\Models\ExceptionRequest;
use App\Models\FilteringPolicy;
use App\Models\Institution;
use App\Models\Laboratory;
use App\Models\ProtectionComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ExtensionController extends Controller
{
    /** Return the effective policy for the authenticated school service identity. */
    public function sync(Request $request): JsonResponse
    {
        $institution = $this->institutionFor($request);

        $policy = FilteringPolicy::query()
            ->where('status', 'active')
            ->where('institution_id', $institution->id)
            ->with('rules.category')
            ->latest('version')
            ->first();

        $policy ??= FilteringPolicy::query()
            ->where('status', 'active')
            ->whereNull('institution_id')
            ->with('rules.category')
            ->latest('version')
            ->first();

        $blockedDomains = BlockedDomain::query()
            ->orderBy('domain')
            ->limit(4500)
            ->pluck('domain')
            ->map(fn (string $domain): string => mb_strtolower(trim($domain)))
            ->filter()
            ->unique()
            ->values();

        $blockedCategories = $policy?->rules
            ->filter(fn ($rule): bool => $rule->action === EnforcementAction::Block)
            ->pluck('category.name')
            ->filter()
            ->unique()
            ->values()
            ->all() ?? [];

        $dnrRules = [
            [
                'id' => 1,
                'priority' => 10,
                'action' => [
                    'type' => 'redirect',
                    'redirect' => [
                        'transform' => [
                            'queryTransform' => [
                                'addOrReplaceParams' => [['key' => 'safe', 'value' => 'active']],
                            ],
                        ],
                    ],
                ],
                'condition' => ['urlFilter' => '||google.com/search', 'resourceTypes' => ['main_frame']],
            ],
            [
                'id' => 2,
                'priority' => 10,
                'action' => [
                    'type' => 'redirect',
                    'redirect' => [
                        'transform' => [
                            'queryTransform' => [
                                'addOrReplaceParams' => [['key' => 'adlt', 'value' => 'strict']],
                            ],
                        ],
                    ],
                ],
                'condition' => ['urlFilter' => '||bing.com/search', 'resourceTypes' => ['main_frame']],
            ],
            [
                'id' => 3,
                'priority' => 10,
                'action' => [
                    'type' => 'modifyHeaders',
                    'requestHeaders' => [[
                        'header' => 'YouTube-Restrict',
                        'operation' => 'set',
                        'value' => 'Strict',
                    ]],
                ],
                'condition' => [
                    'requestDomains' => ['youtube.com', 'www.youtube.com', 'm.youtube.com'],
                    'resourceTypes' => ['main_frame', 'sub_frame', 'xmlhttprequest'],
                ],
            ],
        ];

        foreach ($blockedDomains as $index => $domain) {
            $dnrRules[] = [
                'id' => 1000 + $index,
                'priority' => 1,
                'action' => ['type' => 'block'],
                'condition' => [
                    'urlFilter' => "||{$domain}^",
                    'resourceTypes' => ['main_frame', 'sub_frame', 'xmlhttprequest'],
                ],
            ];
        }

        return response()->json([
            'revision' => $policy?->version ?? 1,
            'institution_id' => $institution->id,
            'institution' => $institution->name,
            'nemis_code' => $institution->nemis_code,
            'policy_name' => $policy?->name ?? 'Standard K-12 Safeguarding Policy',
            'enforce_safesearch' => true,
            'enforce_youtube_strict' => true,
            'blocked_domains' => $blockedDomains,
            'allowed_domains' => [
                'education.go.ke',
                'kicd.ac.ke',
                'nemis.education.go.ke',
                'khanacademy.org',
                'classroom.google.com',
                'wikipedia.org',
            ],
            'blocked_categories' => $blockedCategories,
            'dnr_rules' => $dnrRules,
            'synced_at' => now()->toISOString(),
        ]);
    }

    /** Record extension health without accepting a caller-selected tenant. */
    public function heartbeat(Request $request): JsonResponse
    {
        $institution = $this->institutionFor($request);
        $validated = $request->validate([
            'workstation_id' => ['required', 'string', 'max:100'],
            'device_id' => ['nullable', 'integer'],
            'version' => ['nullable', 'string', 'max:20'],
            'rules_count' => ['nullable', 'integer', 'min:0'],
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

        $component = ProtectionComponent::query()->updateOrCreate(
            [
                'institution_id' => $institution->id,
                'type' => 'browser_extension',
                'identifier' => $validated['workstation_id'],
            ],
            [
                'device_id' => $device?->id,
                'version' => $validated['version'] ?? '2.5.0',
                'health_status' => 'healthy',
                'last_seen_at' => now(),
                'policy_synced_at' => now(),
                'metadata' => [
                    'rules_count' => $validated['rules_count'] ?? 0,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ],
        );

        $device?->update(['last_seen_at' => now(), 'status' => 'active']);

        return response()->json([
            'status' => 'acknowledged',
            'component_id' => $component->id,
            'time' => now()->toISOString(),
        ]);
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

        $schoolCommand = Cache::get("classroom_broadcast_{$institution->id}");
        $laboratoryCommand = $laboratoryId === null
            ? null
            : Cache::get("classroom_broadcast_{$institution->id}_{$laboratoryId}");

        return response()->json([
            'command' => $laboratoryCommand ?? $schoolCommand,
            'focus_locked' => $laboratoryId === null
                ? false
                : (bool) Cache::get("classroom_lock_{$institution->id}_{$laboratoryId}", false),
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
