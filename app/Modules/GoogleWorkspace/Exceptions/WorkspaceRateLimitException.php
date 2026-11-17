<?php

namespace App\Modules\GoogleWorkspace\Exceptions;

/** Quota or rate limit hit: retry after $retryAfter seconds. */
class WorkspaceRateLimitException extends WorkspaceTransientException
{
    public function __construct(string $message = 'Workspace API rate limit reached.', public readonly int $retryAfter = 60)
    {
        parent::__construct($message);
    }
}
