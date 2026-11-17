<?php

namespace App\Modules\Authentication\Http\Resources;

use App\Models\User;
use App\Modules\Organisations\Services\PlatformOverview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $memberships = app(PlatformOverview::class)->memberships($this->resource);

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'global_access' => $this->hasGlobalAccess(),
            'platform_roles' => $this->platformRoles()->pluck('name')->values(),
            'organisations' => $memberships->map(fn (array $membership) => [
                'id' => $membership['organisation']->uuid,
                'name' => $membership['organisation']->name,
                'code' => $membership['organisation']->code,
                'roles' => $membership['roles'],
            ])->values(),
        ];
    }
}
