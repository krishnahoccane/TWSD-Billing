<?php

namespace App\Modules\GoogleWorkspace\Services;

use App\Models\User;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Jobs\GoogleDirectoryJob;
use App\Modules\GoogleWorkspace\Jobs\ImportGoogleDomainsJob;
use App\Modules\GoogleWorkspace\Jobs\ImportGoogleUsersJob;
use App\Modules\GoogleWorkspace\Jobs\ReconcileGoogleAccountsJob;
use App\Modules\GoogleWorkspace\Jobs\SyncGoogleAccountsJob;
use Illuminate\Support\Facades\Cache;

/**
 * Runs a directory synchronisation immediately, inside the web request, when
 * an administrator clicks "Run now" (decision D-24: no queue worker needed for
 * an on-demand sync). The scheduled runs still go through the queue.
 *
 * The same job class does the work either way, so behaviour, logging
 * (google_sync_logs) and failure notifications are identical. One run per
 * type at a time.
 */
class DirectorySyncRunner
{
    /** @var array<string, array{0: class-string<GoogleDirectoryJob>, 1: string}> */
    public const TYPES = [
        'users_full' => [ImportGoogleUsersJob::class, 'Full user import'],
        'users_sync' => [SyncGoogleAccountsJob::class, 'User synchronisation'],
        'domains' => [ImportGoogleDomainsJob::class, 'Domain synchronisation'],
        'reconcile' => [ReconcileGoogleAccountsJob::class, 'Retry stuck operations'],
    ];

    /** Longest a single on-demand run may take (a full import of ~600 users needs far less). */
    public const MAX_SECONDS = 600;

    public static function label(string $type): string
    {
        return self::TYPES[$type][1];
    }

    /**
     * @throws WorkspaceException when another run of the same type is in progress
     * @throws \Throwable whatever the run itself threw (already recorded in the sync log)
     */
    public function run(string $type, User $actor): void
    {
        [$class] = self::TYPES[$type];

        $lock = Cache::lock('wcc:directory-sync:'.$type, self::MAX_SECONDS);

        if (! $lock->get()) {
            throw new WorkspaceException(self::label($type).' is already running. Refresh in a moment to see the result.');
        }

        try {
            // Shared hosts often stop PHP after 30 s; allow a full run (ignored where disabled).
            if (function_exists('set_time_limit')) {
                @set_time_limit(self::MAX_SECONDS);
            }

            $job = new $class($actor->getKey());
            app()->call([$job, 'handle']);
        } finally {
            $lock->release();
        }
    }
}
