<?php

namespace App\Modules\Domains\Enums;

enum DomainRequestStatus: string
{
    case Pending = 'pending';                         // waiting for a Super Admin decision
    case Approved = 'approved';                       // approved; the next step is queued
    case VerificationPending = 'verification_pending'; // DNS TXT instructions issued
    case Verifying = 'verifying';                     // asking Google to verify ownership
    case Verified = 'verified';                       // ownership verified; adding to Workspace
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Failed = 'failed';                           // a step failed; can be retried

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting approval',
            self::VerificationPending => 'Awaiting DNS verification',
            default => ucfirst(str_replace('_', ' ', $this->value)),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Pending, self::VerificationPending => 'warning',
            self::Approved, self::Verifying, self::Verified => 'info',
            self::Failed => 'danger',
            self::Rejected, self::Cancelled => 'neutral',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isOpen()),
        ));
    }
}
