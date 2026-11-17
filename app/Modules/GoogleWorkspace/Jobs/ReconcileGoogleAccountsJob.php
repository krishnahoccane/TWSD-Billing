<?php

namespace App\Modules\GoogleWorkspace\Jobs;

use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Services\InitialPasswordVault;

/**
 * Failed-operation retry (every 30 minutes): re-queues operations that
 * have been pending for more than 10 minutes — for example because a
 * worker died after the local record was written. Safe to repeat because
 * every operation is idempotent. Also purges expired one-time passwords.
 */
class ReconcileGoogleAccountsJob extends GoogleDirectoryJob
{
    public const STUCK_AFTER_MINUTES = 10;

    public function handle(InitialPasswordVault $vault): void
    {
        $vault->purgeExpired();

        GoogleAccount::query()
            ->whereNotNull('pending_operation')
            ->where('pending_since', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (GoogleAccount $account) {
                $payload = [];

                if ($account->pending_operation->value === 'permanent_delete') {
                    $payload = ['deletion_request_id' => $account->deletionRequests()->where('status', 'approved')->value('id')];
                }

                $account->pending_operation->jobClass()::dispatch($account->getKey(), $account->updated_by, $payload);
            });
    }
}
