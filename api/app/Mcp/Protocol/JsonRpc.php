<?php

namespace App\Mcp\Protocol;

/**
 * JSON-RPC 2.0 messages of the MCP transport: classification and answers.
 */
final class JsonRpc
{
    public const VERSION = '2.0';

    public const REQUEST = 'request';

    public const NOTIFICATION = 'notification';

    public const RESPONSE = 'response';

    public const INVALID = 'invalid';

    /** request (method + id), notification (method, no id), response (id + result/error) or invalid. */
    public static function kind(mixed $message): string
    {
        if (! is_array($message) || (array_is_list($message) && $message !== []) || ($message['jsonrpc'] ?? null) !== self::VERSION) {
            return self::INVALID;
        }

        $hasId = array_key_exists('id', $message);
        if (isset($message['method'])) {
            if (! is_string($message['method']) || $message['method'] === '') {
                return self::INVALID;
            }
            if (! $hasId) {
                return self::NOTIFICATION;
            }

            // MCP: request ids are strings or integers, never null
            return is_string($message['id']) || is_int($message['id']) ? self::REQUEST : self::INVALID;
        }

        return $hasId && (array_key_exists('result', $message) || array_key_exists('error', $message))
            ? self::RESPONSE
            : self::INVALID;
    }

    public static function result(string|int|null $id, array|object $result): array
    {
        return ['jsonrpc' => self::VERSION, 'id' => $id, 'result' => $result === [] ? new \stdClass : $result];
    }

    public static function error(string|int|null $id, int $code, string $message, mixed $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return ['jsonrpc' => self::VERSION, 'id' => $id, 'error' => $error];
    }

    public static function fromException(string|int|null $id, McpError $e): array
    {
        return ['jsonrpc' => self::VERSION, 'id' => $id, 'error' => $e->toError()];
    }

    public static function notification(string $method, array|object $params): array
    {
        return ['jsonrpc' => self::VERSION, 'method' => $method, 'params' => $params === [] ? new \stdClass : $params];
    }

    public static function encode(mixed $message): string
    {
        return (string) json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
