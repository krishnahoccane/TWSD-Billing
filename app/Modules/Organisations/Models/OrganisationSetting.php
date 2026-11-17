<?php

namespace App\Modules\Organisations\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-organisation key/value settings. The table exists from Phase B;
 * the first consumers are the billing settings in Phase E.
 */
class OrganisationSetting extends Model
{
    use BelongsToOrganisation;

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
