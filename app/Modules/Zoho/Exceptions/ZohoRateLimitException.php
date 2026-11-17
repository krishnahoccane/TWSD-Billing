<?php

namespace App\Modules\Zoho\Exceptions;

/** Zoho rate limit reached: retry after $retryAfter seconds. */
class ZohoRateLimitException extends ZohoTransientException
{
    public function __construct(string $message = 'Zoho Books rate limit reached.', public readonly int $retryAfter = 60)
    {
        parent::__construct($message);
    }
}
