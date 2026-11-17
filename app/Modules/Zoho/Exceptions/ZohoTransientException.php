<?php

namespace App\Modules\Zoho\Exceptions;

/** Timeout, connection failure, 5xx or an expired token: retry with backoff. */
class ZohoTransientException extends ZohoException {}
