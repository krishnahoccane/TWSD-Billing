<?php

namespace App\Modules\Zoho\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per Zoho Books call: operation, target and outcome — never payloads or tokens. */
class IntegrationSyncLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }
}
