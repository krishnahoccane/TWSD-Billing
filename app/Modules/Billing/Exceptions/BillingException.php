<?php

namespace App\Modules\Billing\Exceptions;

use RuntimeException;

/** A billing run could not be completed for a business reason (shown to the operator). */
class BillingException extends RuntimeException {}
