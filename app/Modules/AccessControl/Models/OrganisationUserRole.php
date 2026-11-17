<?php

namespace App\Modules\AccessControl\Models;

use App\Models\User;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Organisation-scoped role assignment.
 */
class OrganisationUserRole extends Model
{
    protected $fillable = ['organisation_id', 'user_id', 'role_id', 'assigned_by'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
