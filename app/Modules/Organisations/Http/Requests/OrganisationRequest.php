<?php

namespace App\Modules\Organisations\Http\Requests;

use App\Modules\Organisations\Models\Organisation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update. On update the organisation comes from the
 * tenant-scoped route binding and `code` is not accepted (immutable).
 */
class OrganisationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organisation = $this->route('organisation');

        return $organisation instanceof Organisation
            ? $this->user()->can('update', $organisation)
            : $this->user()->can('create', Organisation::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(collect(['code', 'pan', 'gstin', 'country'])
            ->filter(fn (string $key) => is_string($this->input($key)))
            ->mapWithKeys(fn (string $key) => [$key => strtoupper(trim($this->input($key)))])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'pan' => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'primary_contact_name' => ['nullable', 'string', 'max:255'],
            'primary_contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]+$/'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'registered_address' => ['nullable', 'string', 'max:1000'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        if (! $this->route('organisation') instanceof Organisation) {
            $rules['code'] = ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/', Rule::unique('organisations', 'code')];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pan.regex' => 'Enter a valid 10-character PAN (for example ABCDE1234F).',
            'gstin.regex' => 'Enter a valid 15-character GSTIN.',
            'code.regex' => 'The code may contain only letters, numbers and hyphens.',
        ];
    }
}
