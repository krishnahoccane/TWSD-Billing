<?php

namespace App\Modules\Organisations\Http\Resources;

use App\Modules\Organisations\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organisation
 */
class OrganisationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'code' => $this->code,
            'status' => $this->status->value,
            'pan' => $this->pan,
            'gstin' => $this->gstin,
            'primary_contact_name' => $this->primary_contact_name,
            'primary_contact_email' => $this->primary_contact_email,
            'contact_phone' => $this->contact_phone,
            'billing_email' => $this->billing_email,
            'registered_address' => $this->registered_address,
            'billing_address' => $this->billing_address,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            // Internal notes are for staff who can edit the organisation.
            'notes' => $this->when($request->user()?->can('update', $this->resource) ?? false, $this->notes),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
