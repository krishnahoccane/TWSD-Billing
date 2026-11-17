<?php

namespace App\Modules\Organisations\Http\Requests;

use App\Modules\Organisations\Enums\OrganisationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeOrganisationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('organisation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(OrganisationStatus::class)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
