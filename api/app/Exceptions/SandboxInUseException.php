<?php

namespace App\Exceptions;

/**
 * A sandbox runtime cannot be removed while pods still run with it; nothing was changed.
 */
class SandboxInUseException extends \RuntimeException {}
