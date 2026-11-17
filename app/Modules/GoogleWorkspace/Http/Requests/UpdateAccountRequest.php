<?php

namespace App\Modules\GoogleWorkspace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the editable profile fields are accepted. The address, domain and
 * organisation of an account can never be changed through this request.
 */
class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('account'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'recovery_email' => ['nullable', 'email', 'max:255'],
            'recovery_phone' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/'],
        ];

        // Moving a mailbox between organisational units is a platform decision.
        if ($this->user()->hasGlobalAccess()) {
            $rules['organisational_unit_path'] = ['sometimes', 'required', 'string', 'max:500', 'starts_with:/'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['recovery_phone.regex' => 'Enter the phone number in international format, for example +919876543210.'];
    }
}
