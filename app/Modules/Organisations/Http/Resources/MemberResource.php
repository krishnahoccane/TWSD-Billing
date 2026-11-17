<?php

namespace App\Modules\Organisations\Http\Resources;

use App\Modules\AccessControl\Models\OrganisationUserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganisationUserRole
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->user->uuid,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'is_active' => $this->user->is_active,
            ],
            'role' => $this->role->name,
            'role_label' => $this->role->label,
            'assigned_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
