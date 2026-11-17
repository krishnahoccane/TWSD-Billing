<?php

namespace App\Modules\GoogleWorkspace\Http\Resources;

use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GoogleAccount
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'organisation_id' => $this->organisation?->uuid,
            'primary_email' => $this->primary_email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'display_name' => $this->display_name,
            'recovery_email' => $this->recovery_email,
            'recovery_phone' => $this->recovery_phone,
            'organisational_unit_path' => $this->organisational_unit_path,
            'status' => $this->account_status->value,
            'pending_operation' => $this->pending_operation?->value,
            'is_suspended' => $this->is_suspended,
            'billing_status' => $this->billing_status->value,
            'billable_from' => $this->billable_from?->toDateString(),
            'deletion_effective_date' => $this->deletion_effective_date?->toDateString(),
            'last_error' => $this->last_error,
            'google_created_at' => $this->google_created_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
