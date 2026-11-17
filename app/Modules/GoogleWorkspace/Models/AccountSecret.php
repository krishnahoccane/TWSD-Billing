<?php

namespace App\Modules\GoogleWorkspace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An initial mailbox password, encrypted with APP_KEY, viewable exactly
 * once by the user who created the mailbox and purged after viewing or
 * expiry. Never serialised.
 */
class AccountSecret extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted',
            'expires_at' => 'datetime',
            'viewed_at' => 'datetime',
        ];
    }
}
