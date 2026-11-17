<?php

namespace App\Modules\Domains\Jobs;

use App\Models\User;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Domains\Services\DomainRequestProcessor;

/**
 * After approval: obtains the DNS TXT verification value from Google.
 */
class ProcessDomainRequestJob extends DomainRequestJob
{
    public function step(): string
    {
        return 'token';
    }

    protected function run(DomainRequestProcessor $processor, DomainRequest $request, ?User $actor): void
    {
        $processor->issueVerificationToken($request, $actor);
    }
}
