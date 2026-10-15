<?php

namespace App\Workflows\Engine;

/**
 * Wire format of the workflow protocol v1 (docs/workflows-protocol.md).
 *
 * Request (engine → function), HTTP POST, JSON body:
 *   {"runr_workflow": "v1", "signature": "t=…,v1=…", "request": "<JSON string>"}
 * The signature is also sent as the X-RunR-Signature header. `request` is a JSON document
 * embedded as a string, so the signed bytes survive runtimes that parse and re-encode the body.
 *
 * Response (function → engine), HTTP 200, JSON body:
 *   {"runr_workflow": "v1", "ops": [ {op…} ]}                       (execute)
 *   {"runr_workflow": "v1", "sdk": {…}, "workflows": [ {…} ]}      (introspect)
 */
final class Protocol
{
    public const VERSION = 'v1';

    public const HEADER_SIGNATURE = 'X-RunR-Signature';

    public const HEADER_PROTOCOL = 'X-RunR-Workflow-Protocol';

    public const HEADER_RUN = 'X-RunR-Run-Id';

    public const HEADER_WORKFLOW = 'X-RunR-Workflow';

    public const TYPE_EXECUTE = 'execute';

    public const TYPE_INTROSPECT = 'introspect';

    public const OP_STEP_COMPLETED = 'step_completed';

    public const OP_STEP_FAILED = 'step_failed';

    public const OP_SLEEP = 'sleep';

    public const OP_WAIT_FOR_EVENT = 'wait_for_event';

    public const OP_INVOKE = 'invoke';

    public const OP_SEND_EVENT = 'send_event';

    public const OP_RUN_COMPLETED = 'run_completed';

    public const OP_RUN_FAILED = 'run_failed';

    public const OPS = [
        self::OP_STEP_COMPLETED, self::OP_STEP_FAILED, self::OP_SLEEP, self::OP_WAIT_FOR_EVENT,
        self::OP_INVOKE, self::OP_SEND_EVENT, self::OP_RUN_COMPLETED, self::OP_RUN_FAILED,
    ];

    /** Longest step id the engine stores. */
    public const MAX_STEP_ID_LENGTH = 255;

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * Envelope body + headers for a request object.
     *
     * @param  list<string>  $keys  signing keys (current first)
     * @return array{body: string, headers: array<string,string>, request: string}
     */
    public static function envelope(array $request, array $keys, ?int $timestamp = null): array
    {
        $requestJson = self::encode($request);
        $signature = Signature::sign($requestJson, $keys, $timestamp);

        $body = self::encode([
            'runr_workflow' => self::VERSION,
            'signature' => $signature,
            'request' => $requestJson,
        ]);

        $headers = [
            'Content-Type' => 'application/json',
            self::HEADER_PROTOCOL => self::VERSION,
            self::HEADER_SIGNATURE => $signature,
        ];

        if (isset($request['run']['id'])) {
            $headers[self::HEADER_RUN] = (string) $request['run']['id'];
        }
        if (isset($request['workflow'])) {
            $headers[self::HEADER_WORKFLOW] = (string) $request['workflow'];
        }

        return ['body' => $body, 'headers' => $headers, 'request' => $requestJson];
    }

    /**
     * The ops of an execute response.
     *
     * @return list<array<string,mixed>>
     *
     * @throws ProtocolException
     */
    public static function parseOps(string $body): array
    {
        $decoded = self::decodeResponse($body);
        $list = $decoded->ops ?? null;

        if (! is_array($list) || $list === []) {
            throw new ProtocolException('Response has no "ops" list');
        }

        $ops = [];
        foreach (array_values($list) as $i => $op) {
            // Top-level op fields as an array; user values (output, data, events) stay decoded
            // as objects so `{}` and `[]` keep their meaning
            $ops[] = self::validateOp(is_object($op) ? get_object_vars($op) : $op, $i);
        }

        return $ops;
    }

    /**
     * The response document, JSON objects decoded as stdClass.
     *
     * @throws ProtocolException
     */
    public static function decodeResponse(string $body): object
    {
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new ProtocolException('Response is not JSON: '.$e->getMessage());
        }

        if (! is_object($decoded)) {
            throw new ProtocolException('Response is not a JSON object');
        }

        // A runtime wrapper that answered {statusCode, headers, body} without unwrapping it
        if (! isset($decoded->runr_workflow) && isset($decoded->body)) {
            $inner = is_string($decoded->body) ? json_decode($decoded->body, false) : $decoded->body;
            if (is_object($inner) && isset($inner->runr_workflow)) {
                $decoded = $inner;
            }
        }

        $version = $decoded->runr_workflow ?? null;
        if ($version !== self::VERSION) {
            throw new ProtocolException('Unsupported protocol version: '.(is_scalar($version) ? (string) $version : 'none'));
        }

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     *
     * @throws ProtocolException
     */
    private static function validateOp(mixed $op, int $index): array
    {
        if (! is_array($op) || ! is_string($op['op'] ?? null)) {
            throw new ProtocolException("ops[{$index}] has no \"op\"");
        }

        $type = $op['op'];
        if (! in_array($type, self::OPS, true)) {
            throw new ProtocolException("ops[{$index}]: unknown op \"{$type}\"");
        }

        $isStepOp = ! in_array($type, [self::OP_RUN_COMPLETED, self::OP_RUN_FAILED], true);
        if ($isStepOp) {
            $id = $op['id'] ?? null;
            if (! is_string($id) || $id === '' || strlen($id) > self::MAX_STEP_ID_LENGTH) {
                throw new ProtocolException("ops[{$index}] ({$type}): \"id\" must be a non-empty string of at most ".self::MAX_STEP_ID_LENGTH.' bytes');
            }
        }

        if (in_array($type, [self::OP_STEP_FAILED, self::OP_RUN_FAILED], true)) {
            $op['error'] = self::normalizeError($op['error'] ?? null);
        }

        if ($type === self::OP_WAIT_FOR_EVENT && (! is_string($op['event'] ?? null) || $op['event'] === '')) {
            throw new ProtocolException("ops[{$index}] (wait_for_event): \"event\" is required");
        }

        if ($type === self::OP_INVOKE && (! is_string($op['workflow'] ?? null) || $op['workflow'] === '')) {
            throw new ProtocolException("ops[{$index}] (invoke): \"workflow\" is required");
        }

        if ($type === self::OP_SLEEP && ! isset($op['until']) && ! isset($op['duration'])) {
            throw new ProtocolException("ops[{$index}] (sleep): \"until\" or \"duration\" is required");
        }

        if ($type === self::OP_SEND_EVENT) {
            $events = $op['events'] ?? null;
            if (is_object($events) || (is_array($events) && ! array_is_list($events))) {
                $events = [$events];
            }
            if (! is_array($events) || $events === []) {
                throw new ProtocolException("ops[{$index}] (send_event): \"events\" must be a non-empty list");
            }
            $op['events'] = $events;
        }

        return $op;
    }

    /** @return array{name: string, message: string, stack?: string} */
    public static function normalizeError(mixed $error): array
    {
        if (is_string($error)) {
            return ['name' => 'Error', 'message' => mb_substr($error, 0, 4000)];
        }

        if (is_object($error)) {
            $error = get_object_vars($error);
        }

        if (! is_array($error)) {
            return ['name' => 'Error', 'message' => 'Unknown error'];
        }

        $normalized = [
            'name' => mb_substr(is_scalar($error['name'] ?? null) ? (string) $error['name'] : 'Error', 0, 200),
            'message' => mb_substr(is_scalar($error['message'] ?? null) ? (string) $error['message'] : '', 0, 4000),
        ];

        if (isset($error['stack']) && is_string($error['stack'])) {
            $normalized['stack'] = mb_substr($error['stack'], 0, 16000);
        }

        return $normalized;
    }

    /** Byte size of a value once JSON encoded. */
    public static function size(mixed $value): int
    {
        try {
            return strlen(self::encode($value));
        } catch (\JsonException) {
            return PHP_INT_MAX;
        }
    }
}
