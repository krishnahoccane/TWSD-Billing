<?php

namespace App\Modules\GoogleWorkspace\Jobs;

use App\Models\User;
use App\Modules\GoogleWorkspace\Services\GoogleDirectoryImporter;

/**
 * Full paginated import of every user; also flags accounts Google no longer returns. Daily and on demand.
 */
class ImportGoogleUsersJob extends GoogleDirectoryJob
{
    public function handle(GoogleDirectoryImporter $importer): void
    {
        $actor = $this->triggeredBy === null ? null : User::query()->find($this->triggeredBy);

        $importer->importUsers(flagMissing: true, triggeredBy: $actor);
    }
}
