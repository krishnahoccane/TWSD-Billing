<?php

namespace App\Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    protected $guarded = [];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'secrets' => 'encrypted:array',
            'last_tested_at' => 'datetime',
        ];
    }
}
