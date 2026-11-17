<?php

namespace App\Modules\Organisations\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Modules\Organisations\Enums\ContactType;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class OrganisationContact extends Model
{
    use BelongsToOrganisation, HasUuid;

    protected $fillable = ['type', 'name', 'email', 'phone', 'designation'];

    protected function casts(): array
    {
        return ['type' => ContactType::class];
    }
}
