<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartLearnerSessionRequest extends FormRequest
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
        return [
            'learner_id' => ['required', 'integer'],
            'identity_source' => ['required', Rule::in(['school_pin', 'google_workspace', 'microsoft', 'external'])],
            'pin' => ['required_if:identity_source,school_pin', 'nullable', 'string', 'min:4', 'max:20'],
        ];
    }
}
