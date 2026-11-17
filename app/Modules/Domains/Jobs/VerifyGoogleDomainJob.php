<?php

namespace App\Modules\Domains\Jobs;

use App\Models\User;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Domains\Services\DomainRequestProcessor;

/**
 * Asks Google to verify domain ownership through the published DNS record.
 */
class VerifyGoogleDomainJob extends DomainRequestJob
{
    public function step(): string
    {
        return 'verify';
    }

    protected function run(DomainRequestProcessor $processor, DomainRequest $request, ?User $actor): void
    {
        $processor->verify($request, $actor);
    }
}
