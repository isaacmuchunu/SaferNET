<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSecurityEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::Service) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['event_uuid' => ['required', 'uuid'], 'device_id' => ['required', 'integer'], 'learner_session_id' => ['nullable', 'integer'], 'learner_id' => ['nullable', 'integer'], 'incident_id' => ['nullable', 'integer'], 'type' => ['required', 'string', 'max:100'], 'severity' => ['required', 'string', 'in:low,medium,high,critical'], 'description' => ['required', 'string', 'max:4000'], 'response' => ['nullable', 'string', 'max:1000'], 'occurred_at' => ['required', 'date'], 'metadata' => ['nullable', 'array']];
    }
}
