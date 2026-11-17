<?php

namespace App\Modules\AccessControl\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform-level role assignment.
 */
class UserRole extends Model
{
    protected $fillable = ['user_id', 'role_id', 'assigned_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
