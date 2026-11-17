<?php

namespace App\Modules\Installer\Exceptions;

use RuntimeException;

/** A problem the person running the installer can fix (message is safe to show). */
class InstallerException extends RuntimeException {}
