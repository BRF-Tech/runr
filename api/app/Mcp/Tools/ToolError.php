<?php

namespace App\Mcp\Tools;

use App\Mcp\Server\ApiResult;
use RuntimeException;

/**
 * Thrown inside Tool::handle() to end the call with a tool error (isError result). Anything else
 * a tool throws is reported and answered with a generic tool error.
 */
final class ToolError extends RuntimeException
{
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public static function fromApi(ApiResult $result, ?string $context = null): self
    {
        $error = $result->toToolError($context);

        return new self($error->text, (array) ($error->structured['error'] ?? []));
    }

    public function toResult(): ToolResult
    {
        return ToolResult::error($this->getMessage(), array_diff_key($this->details, ['message' => true]));
    }
}
