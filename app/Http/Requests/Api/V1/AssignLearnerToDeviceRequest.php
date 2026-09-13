<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignLearnerToDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->route('device');
        $user = $this->user();

        if ($device instanceof Device && $user !== null
            && $user->hasRole(UserRole::Hoi, UserRole::Clm)
            && $user->institution_id !== $device->institution_id) {
            abort(404);
        }

        return $device instanceof Device && $user !== null
            && ($user->hasRole(UserRole::Cde)
                || ($user->hasRole(UserRole::Hoi, UserRole::Clm)
                    && $user->institution_id === $device->institution_id));
    }

    public function rules(): array
    {
        $institutionId = $this->route('device')?->institution_id;

        return [
            'learner_id' => [
                'required',
                'integer',
                Rule::exists('learners', 'id')->where(fn ($query) => $query
                    ->where('institution_id', $institutionId)
                    ->where('status', 'active')),
            ],
        ];
    }
}
