<?php

namespace App\Modules\GoogleWorkspace\Enums;

enum BillingStatus: string
{
    /** Not yet chargeable: still being created, or not assigned to an organisation. */
    case Pending = 'pending';
    /** Chargeable. Suspended mailboxes stay billable (business rule). */
    case Billable = 'billable';
    /** Soft-deleted or permanently deleted: not charged from the effective date. */
    case NonBillable = 'non_billable';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
