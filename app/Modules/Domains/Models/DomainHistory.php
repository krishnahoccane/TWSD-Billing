<?php

namespace App\Modules\Domains\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of everything that happens to a domain or request.
 */
class DomainHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'domain_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Domain history is append-only.'));
        static::deleting(fn () => throw new LogicException('Domain history is append-only.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
