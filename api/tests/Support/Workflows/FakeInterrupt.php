<?php

namespace Tests\Support\Workflows;

use Exception;

/** Unwinds a fake workflow handler with the op to report. */
class FakeInterrupt extends Exception
{
    public function __construct(public readonly array $op)
    {
        parent::__construct('interrupt: '.($op['op'] ?? '?'));
    }
}
