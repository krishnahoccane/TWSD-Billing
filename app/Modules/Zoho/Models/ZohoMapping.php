<?php

namespace App\Modules\Zoho\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Link between a local record (organisation, invoice, payment) and its
 * Zoho Books counterpart, with the last known Zoho state.
 */
class ZohoMapping extends Model
{
    public const PENDING = 'pending';

    public const SYNCED = 'synced';

    public const FAILED = 'failed';

    public const MISMATCH = 'mismatch';

    public const NEEDS_ATTENTION = 'needs_attention';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'zoho_total_minor' => 'integer',
            'zoho_balance_minor' => 'integer',
            'attempts' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function mappable(): MorphTo
    {
        return $this->morphTo();
    }

    public function tone(): string
    {
        return match ($this->sync_status) {
            self::SYNCED => 'success',
            self::PENDING => 'info',
            self::MISMATCH, self::NEEDS_ATTENTION => 'warning',
            default => 'danger',
        };
    }

    public function label(): string
    {
        return match ($this->sync_status) {
            self::SYNCED => 'Synced with Zoho',
            self::PENDING => 'Waiting to sync',
            self::MISMATCH => 'Differs from Zoho',
            self::NEEDS_ATTENTION => 'Needs attention in Zoho',
            default => 'Sync failed',
        };
    }
}
