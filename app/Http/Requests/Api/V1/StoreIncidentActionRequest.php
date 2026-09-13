<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentActionRequest extends FormRequest
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
        return ['action' => ['required', 'string', 'in:acknowledged,assigned,contacted_guardian,counselled,monitored,resolved,dismissed'], 'notes' => ['nullable', 'string', 'max:4000'], 'metadata' => ['nullable', 'array']];
    }
}
