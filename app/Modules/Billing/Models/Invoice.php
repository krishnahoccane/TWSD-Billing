<?php

namespace App\Modules\Billing\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Support\Concerns\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An invoice is an immutable snapshot once issued: the model itself
 * refuses any change to its charges, tax, period or customer snapshot
 * after the draft stage. Only payment/credit progress and voiding move.
 */
class Invoice extends Model
{
    use BelongsToOrganisation, HasUuid;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const PARTIALLY_PAID = 'partially_paid';

    public const PAID = 'paid';

    public const VOID = 'void';

    /** Fields frozen at the moment of issue. */
    private const FROZEN = [
        'organisation_id', 'billing_cycle_id', 'invoice_number', 'currency', 'period_start', 'period_end',
        'issue_date', 'due_date', 'subtotal_minor', 'adjustments_minor', 'taxable_minor', 'tax_rate_bp',
        'tax_treatment', 'cgst_minor', 'sgst_minor', 'igst_minor', 'tax_minor', 'total_minor', 'organisation_snapshot',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'issue_date' => 'date', 'due_date' => 'date',
            'organisation_snapshot' => 'array', 'issued_at' => 'datetime', 'voided_at' => 'datetime', 'overdue_notified_at' => 'datetime',
            'subtotal_minor' => 'integer', 'adjustments_minor' => 'integer', 'taxable_minor' => 'integer',
            'tax_rate_bp' => 'integer', 'cgst_minor' => 'integer', 'sgst_minor' => 'integer', 'igst_minor' => 'integer',
            'tax_minor' => 'integer', 'total_minor' => 'integer', 'amount_paid_minor' => 'integer',
            'credited_minor' => 'integer', 'balance_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $invoice) {
            if ($invoice->getOriginal('status') === self::DRAFT) {
                return;
            }

            $changed = array_intersect(self::FROZEN, array_keys($invoice->getDirty()));

            if ($changed !== []) {
                throw new LogicException('An issued invoice is immutable ('.implode(', ', $changed).' cannot change).');
            }
        });

        static::deleting(fn () => throw new LogicException('Invoices are never deleted; void them or issue a credit note.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort')->orderBy('id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class, 'billing_cycle_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::ISSUED, self::PARTIALLY_PAID], true);
    }

    /** Overdue is derived, never stored: open and past its due date. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->balance_minor > 0 && $this->due_date !== null
            && $this->due_date->lt(CarbonImmutable::now(config('wcc.billing.timezone'))->startOfDay());
    }

    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status;
    }

    public function statusTone(): string
    {
        return match ($this->displayStatus()) {
            self::PAID => 'success',
            self::PARTIALLY_PAID, self::ISSUED => 'info',
            'overdue' => 'danger',
            self::DRAFT => 'warning',
            default => 'neutral',
        };
    }
}
