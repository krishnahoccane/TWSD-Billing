<?php

namespace App\Modules\GoogleWorkspace\Exceptions;

use RuntimeException;

/**
 * A provider call failed and retrying will not help
 * (bad request, forbidden, invalid configuration...).
 */
class WorkspaceException extends RuntimeException {}
