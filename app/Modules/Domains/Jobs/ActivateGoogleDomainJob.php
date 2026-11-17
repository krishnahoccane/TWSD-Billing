<?php

namespace App\Modules\Domains\Jobs;

use App\Models\User;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Domains\Services\DomainRequestProcessor;

/**
 * Adds the verified secondary domain to Google Workspace and activates it for the organisation.
 */
class ActivateGoogleDomainJob extends DomainRequestJob
{
    public function step(): string
    {
        return 'activate';
    }

    protected function run(DomainRequestProcessor $processor, DomainRequest $request, ?User $actor): void
    {
        $processor->activate($request, $actor);
    }
}
