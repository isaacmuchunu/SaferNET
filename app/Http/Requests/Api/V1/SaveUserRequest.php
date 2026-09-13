<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use App\Services\Notifications\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUserRequest extends FormRequest
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

        return [
            'name' => [$presence, 'string', 'max:255'],
            'email' => [$presence, 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'phone' => [$this->isMethod('post') ? 'required' : 'sometimes', 'nullable', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && PhoneNumber::toE164((string) $value) === null) {
                    $fail('Enter a valid mobile number, for example 0712 345 678 or +254 712 345 678.');
                }
            }],
            'avatar' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=1600,max_height=1600'],
            'password' => [$this->isMethod('post') ? 'prohibited' : 'sometimes', 'nullable', 'string', 'min:12'],
            'role' => [$presence, 'string', 'in:cde,scde,hoi,clm'],
            'status' => ['sometimes', 'string', 'in:active,suspended'],
            'subcounty_id' => [Rule::requiredIf($this->isMethod('post') && $this->input('role') === 'scde'), 'nullable', 'integer', 'exists:subcounties,id'],
            'institution_id' => [Rule::requiredIf(
                $this->isMethod('post')
                && in_array($this->input('role'), ['hoi', 'clm'], true)
                && ! $this->user()?->hasRole(UserRole::Hoi)
            ), 'nullable', 'integer', 'exists:institutions,id'],
        ];
    }
}
