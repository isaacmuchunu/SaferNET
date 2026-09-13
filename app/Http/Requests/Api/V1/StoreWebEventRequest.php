<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\EnforcementAction;
use App\Enums\RequestKind;
use App\Enums\Severity;
use App\Enums\UserRole;
use App\Http\Requests\Concerns\NormalisesClientTimestamps;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebEventRequest extends FormRequest
{
    use NormalisesClientTimestamps;

    /** Client timestamps arrive in UTC; store them in the application timezone. */
    protected function prepareForValidation(): void
    {
        $this->normaliseTimestamps(['occurred_at']);
    }

    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::Service) ?? false;
    }

    public function rules(): array
    {
        return [
            'event_uuid' => ['required', 'uuid'],
            'learner_session_id' => ['required', 'integer'],
            'filtering_policy_id' => ['nullable', 'integer'],
            'policy_rule_id' => ['nullable', 'integer'],
            'content_category_id' => ['nullable', 'integer'],
            'url' => ['required', 'url:http,https', 'max:4096'],
            'domain' => ['required', 'string', 'max:253'],
            'request_kind' => ['required', Rule::enum(RequestKind::class)],
            'action' => ['required', Rule::enum(EnforcementAction::class)],
            'enforcement_source' => ['required', Rule::in(['gateway', 'endpoint', 'extension'])],
            'severity' => ['required', Rule::enum(Severity::class)],
            'reason' => ['required', 'string', 'max:1000'],
            'occurred_at' => ['required', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
