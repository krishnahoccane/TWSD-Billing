<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A snapshot line. Lines can only be written or replaced while the
 * invoice is a draft.
 */
class InvoiceItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date',
            'unit_price_minor' => 'integer', 'amount_minor' => 'integer', 'tax_rate_bp' => 'integer',
            'tax_minor' => 'integer', 'total_minor' => 'integer', 'days' => 'integer', 'days_in_period' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (self $item) {
            $status = DB::table('invoices')->where('id', $item->invoice_id)->value('status');

            if ($status !== Invoice::DRAFT) {
                throw new LogicException('Lines of an issued invoice are immutable.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
