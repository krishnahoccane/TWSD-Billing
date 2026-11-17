<?php

namespace App\Modules\GoogleWorkspace\Exceptions;

/** Timeout, connection failure or 5xx: retry with backoff. */
class WorkspaceTransientException extends WorkspaceException {}
