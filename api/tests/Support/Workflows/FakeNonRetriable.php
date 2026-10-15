<?php

namespace Tests\Support\Workflows;

use RuntimeException;

/** A failure the fake SDK reports with retriable: false. */
class FakeNonRetriable extends RuntimeException {}
