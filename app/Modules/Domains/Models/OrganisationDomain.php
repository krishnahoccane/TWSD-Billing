<?php

namespace App\Modules\Domains\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Modules\Domains\Enums\DomainType;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class OrganisationDomain extends Model
{
    use BelongsToOrganisation, HasUuid;

    protected $fillable = ['domain_name', 'domain_type'];

    protected $attributes = [
        'domain_type' => 'secondary',
        'verification_status' => 'unverified',
        'mx_status' => 'unknown',
        'is_primary' => false,
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'domain_type' => DomainType::class,
            'is_primary' => 'boolean',
        ];
    }
}
