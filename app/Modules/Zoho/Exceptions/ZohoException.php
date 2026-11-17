<?php

namespace App\Modules\Zoho\Exceptions;

use RuntimeException;

/** A Zoho Books call failed and retrying will not help (validation error, permission, bad configuration). */
class ZohoException extends RuntimeException {}
