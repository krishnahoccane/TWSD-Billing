<?php

namespace App\Modules\GoogleWorkspace\Jobs;

use App\Models\User;
use App\Modules\GoogleWorkspace\Services\GoogleDirectoryImporter;

/**
 * Periodic user reconciliation (every 15 minutes): new users and changed names, addresses and suspension state.
 */
class SyncGoogleAccountsJob extends GoogleDirectoryJob
{
    public function handle(GoogleDirectoryImporter $importer): void
    {
        $actor = $this->triggeredBy === null ? null : User::query()->find($this->triggeredBy);

        $importer->importUsers(flagMissing: false, triggeredBy: $actor);
    }
}
