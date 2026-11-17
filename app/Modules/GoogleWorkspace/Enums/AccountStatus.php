<?php

namespace App\Modules\GoogleWorkspace\Enums;

/**
 * Local lifecycle of a mailbox. Separate from Google's own `suspended`
 * flag (google_accounts.is_suspended) and from billing status.
 */
enum AccountStatus: string
{
    case PendingCreation = 'pending_creation';
    case Active = 'active';
    case SuspensionPending = 'suspension_pending';
    case Suspended = 'suspended';
    case ReactivationPending = 'reactivation_pending';
    case DeletionPending = 'deletion_pending';
    case SoftDeleted = 'soft_deleted';
    case PermanentlyDeleted = 'permanently_deleted';
    case CreationFailed = 'creation_failed';
    case SyncFailed = 'sync_failed';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /** Badge tone for <x-ui.badge>. */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::PendingCreation, self::SuspensionPending, self::ReactivationPending, self::DeletionPending => 'info',
            self::CreationFailed, self::SyncFailed => 'danger',
            self::SoftDeleted, self::PermanentlyDeleted => 'neutral',
        };
    }

    public function isPending(): bool
    {
        return in_array($this, [
            self::PendingCreation, self::SuspensionPending, self::ReactivationPending, self::DeletionPending,
        ], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [$status->value => $status->label()])->all();
    }
}
