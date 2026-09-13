<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreExceptionRequestRequest extends FormRequest
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
        return ['institution_id' => ['nullable', 'integer'], 'content_category_id' => ['nullable', 'integer', 'exists:content_categories,id'], 'domain' => ['required', 'string', 'max:253'], 'reason' => ['required', 'string', 'max:2000'], 'expires_at' => ['nullable', 'date', 'after:now']];
    }
}
