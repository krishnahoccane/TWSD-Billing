<?php

namespace App\Modules\Licensing\Exceptions;

use RuntimeException;

/**
 * A business-rule refusal with a stable machine code, rendered by the
 * storefront and license-server APIs as
 * { "error": { "code", "message", "field" } } with $status.
 */
class LicensingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }
}
