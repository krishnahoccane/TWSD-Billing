<?php

namespace App\Modules\GoogleWorkspace\Http\Requests;

use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    private ?Organisation $organisation = null;

    /**
     * The submitted organisation uuid is only a selector: it is resolved
     * inside the set of organisations where this user may create accounts.
     * Anything else is treated as non-existent (404).
     */
    public function authorize(): bool
    {
        $this->organisation = Organisation::query()
            ->visibleTo($this->user(), Ability::AccountsCreate)
            ->where('uuid', (string) $this->input('organisation'))
            ->first();

        abort_if($this->organisation === null, 404);

        return $this->user()->can('create', [GoogleAccount::class, $this->organisation]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['username' => strtolower(trim((string) $this->input('username')))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organisation' => ['required', 'uuid'],
            'domain' => ['required', 'uuid'],
            'username' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]([a-z0-9._\-]*[a-z0-9])?$/', 'not_regex:/\.\./'],
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'recovery_email' => ['nullable', 'email', 'max:255'],
            'recovery_phone' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => 'Use letters, numbers, dots, hyphens or underscores, starting and ending with a letter or number.',
            'username.not_regex' => 'The username cannot contain two dots in a row.',
            'recovery_phone.regex' => 'Enter the phone number in international format, for example +919876543210.',
        ];
    }

    public function organisation(): Organisation
    {
        return $this->organisation;
    }

    /**
     * The domain is looked up inside the resolved organisation, so another
     * organisation's domain uuid cannot be used.
     */
    public function domain(): OrganisationDomain
    {
        return OrganisationDomain::query()
            ->where('organisation_id', $this->organisation->getKey())
            ->where('uuid', $this->validated('domain'))
            ->firstOr(fn () => abort(422, 'Choose one of this organisation\'s domains.'));
    }
}
