<?php

namespace App\Modules\GoogleWorkspace\Models;

use App\Models\User;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleSyncLog extends Model
{
    use HasUuid;

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected $attributes = [
        'pages' => 0, 'fetched' => 0, 'created' => 0, 'updated' => 0,
        'unassigned' => 0, 'missing' => 0, 'errors' => 0,
    ];

    protected function casts(): array
    {
        return ['details' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
