<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\InstitutionStatus;
use App\Enums\UserRole;
use App\Services\Notifications\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInstitutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::Cde, UserRole::Scde) ?? false;
    }

    public function rules(): array
    {
        return [
            'subcounty_id' => ['required', 'integer', Rule::exists('subcounties', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'max:255'],
            'nemis_code' => ['required', 'string', 'max:50', Rule::unique('institutions')],
            'institution_type' => ['required', Rule::in(['primary', 'junior', 'secondary', 'special'])],
            'ownership' => ['required', Rule::in(['public', 'private'])],
            'physical_location' => ['required', 'string', 'max:255'],
            'hoi_name' => ['required', 'string', 'max:255'],
            'hoi_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'hoi_phone' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (PhoneNumber::toE164((string) $value) === null) {
                    $fail('Enter a valid mobile number, for example 0712 345 678 or +254 712 345 678.');
                }
            }],
            'learner_population' => ['required', 'integer', 'min:0'],
            'computing_devices_count' => ['required', 'integer', 'min:0'],
            'laboratories_count' => ['required', 'integer', 'min:0'],
            'connectivity_type' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::enum(InstitutionStatus::class)],
        ];
    }
}
