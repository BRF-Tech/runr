<?php

namespace Tests\Support\Workflows;

use RuntimeException;

/** Thrown into a fake handler for a memoized failed step (catchable by the handler). */
class FakeStepFailed extends RuntimeException {}
