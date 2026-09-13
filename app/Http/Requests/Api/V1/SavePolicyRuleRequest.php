<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SavePolicyRuleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('post') ? 'required' : 'sometimes';

        return ['content_category_id' => [$presence, 'integer', 'exists:content_categories,id'], 'action' => [$presence, 'string', 'in:allow,warn,block'], 'severity' => [$presence, 'string', 'in:low,medium,high,critical'], 'is_locked' => ['sometimes', 'boolean'], 'counts_toward_incidents' => ['sometimes', 'boolean'], 'threshold_count' => ['nullable', 'integer', 'min:1'], 'threshold_window_minutes' => ['nullable', 'integer', 'min:1'], 'notify_immediately' => ['sometimes', 'boolean']];
    }
}
