<?php

namespace App\Workflows\Engine;

use RuntimeException;

/** The function's answer does not follow the workflow protocol. */
class ProtocolException extends RuntimeException {}
