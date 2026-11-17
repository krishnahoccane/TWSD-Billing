<?php

namespace App\Modules\Domains\Jobs;

use App\Models\User;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Domains\Services\DomainRequestProcessor;

/**
 * Removes an approved domain from Google Workspace after a final dependency check.
 */
class RemoveGoogleDomainJob extends DomainRequestJob
{
    public function step(): string
    {
        return 'remove';
    }

    protected function run(DomainRequestProcessor $processor, DomainRequest $request, ?User $actor): void
    {
        $processor->remove($request, $actor);
    }
}
