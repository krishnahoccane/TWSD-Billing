<?php

namespace App\Modules\Organisations\Http\Requests;

use App\Modules\Organisations\Services\OrganisationMemberService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageMembers', $this->route('organisation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            // Only organisation-assignable roles; Super Admin can never be granted here.
            'role' => ['required', Rule::in(array_keys(OrganisationMemberService::assignableRoles()))],
        ];
    }
}
