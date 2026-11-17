<?php

namespace App\Modules\GoogleWorkspace\Jobs;

use App\Models\User;
use App\Modules\GoogleWorkspace\Services\GoogleDirectoryImporter;

/**
 * Domain and organisational-unit reconciliation (every 6 hours).
 */
class ImportGoogleDomainsJob extends GoogleDirectoryJob
{
    public function handle(GoogleDirectoryImporter $importer): void
    {
        $actor = $this->triggeredBy === null ? null : User::query()->find($this->triggeredBy);

        $importer->importDomains($actor);
    }
}
