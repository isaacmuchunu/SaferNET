<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubcountyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::Cde) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('subcounties')],
            'code' => ['required', 'alpha_dash', 'max:20', Rule::unique('subcounties')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
