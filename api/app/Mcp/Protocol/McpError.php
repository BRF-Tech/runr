<?php

namespace App\Mcp\Protocol;

use RuntimeException;

/**
 * A JSON-RPC error answer (protocol errors: unknown method, invalid params, unknown tool,
 * resource not found). Tool execution errors are results with isError instead.
 */
final class McpError extends RuntimeException
{
    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INVALID_PARAMS = -32602;

    public const INTERNAL_ERROR = -32603;

    // MCP: resources/read of an unknown (or not visible) resource
    public const RESOURCE_NOT_FOUND = -32002;

    public function __construct(int $code, string $message, public readonly mixed $data = null)
    {
        parent::__construct($message, $code);
    }

    public static function invalidParams(string $message, mixed $data = null): self
    {
        return new self(self::INVALID_PARAMS, $message, $data);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(self::METHOD_NOT_FOUND, "Method not found: {$method}");
    }

    public static function resourceNotFound(string $uri): self
    {
        return new self(self::RESOURCE_NOT_FOUND, 'Resource not found', ['uri' => $uri]);
    }

    /** The JSON-RPC error object. */
    public function toError(): array
    {
        $error = ['code' => $this->getCode(), 'message' => $this->getMessage()];
        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return $error;
    }
}
