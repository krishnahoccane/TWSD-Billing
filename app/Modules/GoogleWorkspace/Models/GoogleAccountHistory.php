<?php

namespace App\Modules\GoogleWorkspace\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only lifecycle history of one mailbox. Phase E derives billable
 * periods from these events, so rows are never changed or removed.
 */
class GoogleAccountHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'google_account_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Account history is append-only.'));
        static::deleting(fn () => throw new LogicException('Account history is append-only.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
