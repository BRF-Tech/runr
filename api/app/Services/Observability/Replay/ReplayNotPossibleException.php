<?php

namespace App\Services\Observability\Replay;

use RuntimeException;

/** The project has no endpoint a replay can be sent to (queue / schedule trigger, no domain). */
class ReplayNotPossibleException extends RuntimeException {}
