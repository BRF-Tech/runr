<?php

namespace App\Services\Observability\LoadTest;

/**
 * A load test that may not start (or was refused by the region): the HTTP status and the code the
 * API answers with (docs/load-test.md#errors), and the message for the caller.
 */
final class LoadTestException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $errorCode = 'load_test_refused', public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /** @return array{message: string, code: string} */
    public function toArray(): array
    {
        return ['message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra;
    }
}
