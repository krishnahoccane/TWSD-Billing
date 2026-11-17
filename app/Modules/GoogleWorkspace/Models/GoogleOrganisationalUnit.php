<?php

namespace App\Modules\GoogleWorkspace\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleOrganisationalUnit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }
}
