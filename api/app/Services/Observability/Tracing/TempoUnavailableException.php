<?php

namespace App\Services\Observability\Tracing;

use RuntimeException;

/** Tempo could not be reached or answered with an error. */
class TempoUnavailableException extends RuntimeException {}
