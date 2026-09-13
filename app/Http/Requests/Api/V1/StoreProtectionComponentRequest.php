<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProtectionComponentRequest extends FormRequest
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
        return ['device_id' => ['nullable', 'integer'], 'type' => ['required', 'string', 'in:gateway,endpoint_agent,browser_extension,dns_filter'], 'identifier' => ['required', 'string', 'max:255'], 'version' => ['nullable', 'string', 'max:100'], 'health_status' => ['required', 'string', 'in:healthy,degraded,offline,unknown'], 'policy_synced_at' => ['nullable', 'date'], 'metadata' => ['nullable', 'array']];
    }
}
