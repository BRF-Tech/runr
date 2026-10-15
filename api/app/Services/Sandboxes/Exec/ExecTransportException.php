<?php

namespace App\Services\Sandboxes\Exec;

use RuntimeException;

/** kubectl / the cluster refused the exec (pod gone, API server unreachable, …). */
class ExecTransportException extends RuntimeException {}
