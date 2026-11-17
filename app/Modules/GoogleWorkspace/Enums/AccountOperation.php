<?php

namespace App\Modules\GoogleWorkspace\Enums;

use App\Modules\GoogleWorkspace\Jobs\CreateGoogleUserJob;
use App\Modules\GoogleWorkspace\Jobs\PermanentlyDeleteGoogleUserJob;
use App\Modules\GoogleWorkspace\Jobs\ReactivateGoogleUserJob;
use App\Modules\GoogleWorkspace\Jobs\RestoreGoogleUserJob;
use App\Modules\GoogleWorkspace\Jobs\SoftDeleteGoogleUserJob;
use App\Modules\GoogleWorkspace\Jobs\SuspendGoogleUserJob;

/**
 * The operation currently in flight for an account
 * (google_accounts.pending_operation).
 */
enum AccountOperation: string
{
    case Create = 'create';
    case Suspend = 'suspend';
    case Reactivate = 'reactivate';
    case SoftDelete = 'soft_delete';
    case Restore = 'restore';
    case PermanentDelete = 'permanent_delete';

    public function pendingStatus(): AccountStatus
    {
        return match ($this) {
            self::Create => AccountStatus::PendingCreation,
            self::Suspend => AccountStatus::SuspensionPending,
            self::Reactivate, self::Restore => AccountStatus::ReactivationPending,
            self::SoftDelete, self::PermanentDelete => AccountStatus::DeletionPending,
        };
    }

    /**
     * @return class-string
     */
    public function jobClass(): string
    {
        return match ($this) {
            self::Create => CreateGoogleUserJob::class,
            self::Suspend => SuspendGoogleUserJob::class,
            self::Reactivate => ReactivateGoogleUserJob::class,
            self::SoftDelete => SoftDeleteGoogleUserJob::class,
            self::Restore => RestoreGoogleUserJob::class,
            self::PermanentDelete => PermanentlyDeleteGoogleUserJob::class,
        };
    }
}
