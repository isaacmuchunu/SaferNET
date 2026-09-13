<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveLearnerRequest extends FormRequest
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

        return ['institution_id' => ['nullable', 'integer'], 'learner_group_id' => ['nullable', 'integer'], 'learner_number' => [$presence, 'string', 'max:100'], 'first_name' => [$presence, 'string', 'max:100'], 'last_name' => [$presence, 'string', 'max:100'], 'pin' => ['nullable', 'string', 'min:4', 'max:20'], 'external_identity' => ['nullable', 'string', 'max:255'], 'status' => ['sometimes', 'string', 'in:active,inactive,graduated,transferred']];
    }
}
