<?php

namespace App\Workflows\Engine;

use RuntimeException;

/**
 * A refused workflow operation (validation, limits, state) with the HTTP status the API answers.
 */
class WorkflowException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }

    public static function tooLarge(string $message): self
    {
        return new self($message, 413, 'payload_too_large');
    }

    public static function conflict(string $message, ?string $code = null): self
    {
        return new self($message, 409, $code);
    }

    public static function invalid(string $message, ?string $code = null): self
    {
        return new self($message, 422, $code);
    }
}
