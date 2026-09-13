<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveFilteringPolicyRequest extends FormRequest
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

        return ['parent_id' => ['nullable', 'integer'], 'institution_id' => ['nullable', 'integer'], 'learner_group_id' => ['nullable', 'integer'], 'name' => [$presence, 'string', 'max:255'], 'level' => [$presence, 'string', 'in:county,institution,group'], 'status' => ['sometimes', 'string', 'in:draft,active,archived'], 'version' => ['sometimes', 'integer', 'min:1'], 'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from']];
    }
}
