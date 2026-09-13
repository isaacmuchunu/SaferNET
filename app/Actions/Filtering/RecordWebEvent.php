<?php

namespace App\Actions\Filtering;

use App\Enums\EnforcementAction;
use App\Enums\RequestKind;
use App\Enums\Severity;
use App\Enums\UserRole;
use App\Models\ContentCategory;
use App\Models\FilteringPolicy;
use App\Models\Incident;
use App\Models\LearnerSession;
use App\Models\PolicyRule;
use App\Models\User;
use App\Models\WebEvent;
use App\Notifications\IncidentCreatedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class RecordWebEvent
{
    /** @param array<string, mixed> $data */
    public function handle(array $data, User $actor): WebEvent
    {
        return DB::transaction(function () use ($data, $actor): WebEvent {
            $session = LearnerSession::query()
                ->visibleTo($actor)
                ->lockForUpdate()
                ->findOrFail($data['learner_session_id']);

            if ($session->ended_at !== null) {
                throw ValidationException::withMessages([
                    'learner_session_id' => 'Web activity cannot be attached to an ended learner session.',
                ]);
            }

            $existing = WebEvent::withoutGlobalScope('tenant')
                ->where('event_uuid', $data['event_uuid'])
                ->first();

            if ($existing !== null) {
                abort_if($existing->institution_id !== $session->institution_id, 409, 'The event identifier has already been used.');

                return $existing;
            }

            $data = $this->validatePolicyReferences($data, $session);

            $event = WebEvent::create($data + [
                'institution_id' => $session->institution_id,
                'learner_id' => $session->learner_id,
                'device_id' => $session->device_id,
            ]);

            $session->update(['last_activity_at' => $event->occurred_at]);
            $incident = $this->detectIncident($event);

            if ($incident !== null) {
                $event->update(['incident_id' => $incident->id]);
            }

            return $event->fresh(['incident']);
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validatePolicyReferences(array $data, LearnerSession $session): array
    {
        $policy = null;

        if (isset($data['policy_rule_id'])) {
            $rule = PolicyRule::query()
                ->with(['policy', 'category'])
                ->find($data['policy_rule_id']);

            if ($rule === null || ! $this->policyAppliesToSession($rule->policy, $session)) {
                throw ValidationException::withMessages(['policy_rule_id' => 'The policy rule is not valid for this learner session.']);
            }

            if (isset($data['filtering_policy_id']) && (int) $data['filtering_policy_id'] !== $rule->filtering_policy_id) {
                throw ValidationException::withMessages(['filtering_policy_id' => 'The filtering policy does not match the policy rule.']);
            }

            if (isset($data['content_category_id']) && (int) $data['content_category_id'] !== $rule->content_category_id) {
                throw ValidationException::withMessages(['content_category_id' => 'The content category does not match the policy rule.']);
            }

            if ($data['action'] !== $rule->action->value || $data['severity'] !== $rule->severity->value) {
                throw ValidationException::withMessages(['policy_rule_id' => 'The reported enforcement does not match the policy rule.']);
            }

            $data['filtering_policy_id'] = $rule->filtering_policy_id;
            $data['content_category_id'] = $rule->content_category_id;

            return $data;
        }

        if (isset($data['filtering_policy_id'])) {
            $policy = FilteringPolicy::query()->find($data['filtering_policy_id']);
        }

        if ($policy === null && isset($data['filtering_policy_id'])) {
            throw ValidationException::withMessages(['filtering_policy_id' => 'The filtering policy is not valid for this learner session.']);
        }

        if ($policy !== null && ! $this->policyAppliesToSession($policy, $session)) {
            throw ValidationException::withMessages(['filtering_policy_id' => 'The filtering policy is not valid for this learner session.']);
        }

        if (isset($data['content_category_id'])
            && ! ContentCategory::query()->whereKey($data['content_category_id'])->exists()) {
            throw ValidationException::withMessages(['content_category_id' => 'The content category is invalid.']);
        }

        return $data;
    }

    private function policyAppliesToSession(FilteringPolicy $policy, LearnerSession $session): bool
    {
        if ($policy->institution_id === null) {
            return $policy->learner_group_id === null;
        }

        if ($policy->institution_id !== $session->institution_id) {
            return false;
        }

        return $policy->learner_group_id === null
            || $policy->learner_group_id === $session->learner()->value('learner_group_id');
    }

    private function detectIncident(WebEvent $event): ?Incident
    {
        if ($event->action !== EnforcementAction::Block || $event->request_kind !== RequestKind::TopLevel) {
            return null;
        }

        $rule = $event->policy_rule_id === null ? null : PolicyRule::query()->find($event->policy_rule_id);

        if ($rule !== null && ! $rule->counts_toward_incidents) {
            return null;
        }

        $threshold = $rule?->threshold_count ?? (in_array($event->severity, [Severity::High, Severity::Critical], true) ? 1 : 5);
        $windowMinutes = $rule?->threshold_window_minutes ?? 30;
        $occurredAt = CarbonImmutable::parse($event->occurred_at);
        $eventCount = WebEvent::query()
            ->where('learner_id', $event->learner_id)
            ->where('action', EnforcementAction::Block->value)
            ->where('request_kind', RequestKind::TopLevel->value)
            ->when($event->content_category_id, fn ($query, $categoryId) => $query->where('content_category_id', $categoryId))
            ->whereBetween('occurred_at', [$occurredAt->subMinutes($windowMinutes), $occurredAt])
            ->count();

        if ($eventCount < $threshold && ! ($rule?->notify_immediately ?? false)) {
            return null;
        }

        $incident = Incident::query()
            ->where('learner_id', $event->learner_id)
            ->where('content_category_id', $event->content_category_id)
            ->whereIn('status', ['open', 'under_review', 'monitored'])
            ->latest('id')
            ->first();

        if ($incident !== null) {
            $incident->update([
                'event_count' => $eventCount,
                'last_detected_at' => $event->occurred_at,
            ]);

            return $incident;
        }

        $incident = Incident::create([
            'institution_id' => $event->institution_id,
            'learner_id' => $event->learner_id,
            'device_id' => $event->device_id,
            'filtering_policy_id' => $event->filtering_policy_id,
            'policy_rule_id' => $event->policy_rule_id,
            'content_category_id' => $event->content_category_id,
            'severity' => $event->severity,
            'event_count' => $eventCount,
            'first_detected_at' => $event->occurred_at,
            'last_detected_at' => $event->occurred_at,
        ]);

        $recipients = User::query()
            ->where('institution_id', $event->institution_id)
            ->where('role', UserRole::Hoi->value)
            ->where('status', 'active')
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, (new IncidentCreatedNotification($incident))->afterCommit());
            $incident->update(['notified_at' => now()]);
        }

        return $incident;
    }
}
