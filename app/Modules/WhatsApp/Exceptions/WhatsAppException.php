<?php

namespace App\Modules\WhatsApp\Exceptions;

use RuntimeException;

/** A message was not accepted. $retryable: a timeout or server error worth retrying. */
class WhatsAppException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
