<?php

namespace App\Modules\GoogleWorkspace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per provider call. Holds the operation, target and outcome —
 * never request bodies, passwords or tokens.
 */
class GoogleApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
