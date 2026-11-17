<?php

namespace App\Modules\Audit\Models;

use App\Models\User;
use App\Modules\Organisations\Models\Organisation;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only. Rows can be created but never updated or deleted through
 * Eloquent. (Bulk query-builder writes are prevented by convention and
 * code review; the production DB user should additionally lack
 * UPDATE/DELETE on this table — see docs/SECURITY.md.)
 */
class AuditLog extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'previous_data' => 'array',
            'new_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Audit logs are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new LogicException('Audit logs are append-only and cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class)->withTrashed();
    }
}
