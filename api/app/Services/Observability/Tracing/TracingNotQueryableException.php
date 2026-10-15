<?php

namespace App\Services\Observability\Tracing;

use RuntimeException;

/** The project's region has no queryable trace backend (off, external export, not ready). */
class TracingNotQueryableException extends RuntimeException {}
