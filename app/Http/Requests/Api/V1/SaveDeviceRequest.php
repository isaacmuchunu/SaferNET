<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveDeviceRequest extends FormRequest
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

        return ['institution_id' => ['nullable', 'integer'], 'laboratory_id' => ['nullable', 'integer'], 'device_group_id' => ['nullable', 'integer'], 'asset_tag' => [$presence, 'string', 'max:100'], 'serial_number' => ['nullable', 'string', 'max:255'], 'hostname' => ['nullable', 'string', 'max:255'], 'platform' => ['sometimes', 'string', 'in:windows,linux,chromeos,android,ios,macos'], 'usage_type' => ['sometimes', 'string', 'in:learner,staff,shared'], 'status' => ['sometimes', 'string', 'in:active,offline,attention_required,retired']];
    }
}
