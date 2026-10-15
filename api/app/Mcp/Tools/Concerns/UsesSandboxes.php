<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\ApiResult;
use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * The Sandbox API (docs/sandbox-api.md) for the sandbox tools: calls through the API routes as the
 * caller, the concise sandbox shape, output caps for the model and errors with the API's
 * `reason` and a hint what to do next.
 */
trait UsesSandboxes
{
    /** States a sandbox does not leave again. */
    protected static array $endedStates = ['terminating', 'terminated', 'failed'];

    /** The `sandbox` argument every sandbox tool takes. */
    protected static function sandboxArgument(): array
    {
        return Schema::string('The sandbox id (sandbox_create and sandboxes_list return it).', ['minLength' => 1, 'maxLength' => 64, 'pattern' => '^[0-9A-Za-z]+$']);
    }

    /** A command: a string run by /bin/sh -c, or an argv list. */
    protected static function commandArgument(string $description): array
    {
        return [
            'type' => ['string', 'array'],
            'items' => ['type' => 'string', 'maxLength' => 65536],
            'minLength' => 1,
            'minItems' => 1,
            'maxItems' => 1000,
            'description' => $description,
        ];
    }

    /** Extra environment variables of one command (names [A-Za-z_][A-Za-z0-9_]*). */
    protected static function envArgument(string $description): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => ['type' => 'string', 'maxLength' => 32768],
            'propertyNames' => ['pattern' => '^[A-Za-z_][A-Za-z0-9_]*$'],
            'maxProperties' => 100,
            'description' => $description,
        ];
    }

    /** /sandboxes/{id}/... with every segment encoded. */
    protected static function sandboxPath(string $sandbox, string|int ...$segments): string
    {
        return InternalApi::path('sandboxes', $sandbox, ...$segments);
    }

    /**
     * One Sandbox API call; a refusal becomes a tool error with the API's reason.
     *
     * @throws ToolError
     */
    protected function sandboxCall(ToolContext $context, string $method, string $uri, array $body = [], array $query = [], ?string $what = null): ApiResult
    {
        $result = $context->api()->request($method, $uri, $body, $query);
        if (! $result->ok()) {
            throw $this->sandboxError($result, $what);
        }

        return $result;
    }

    /** A Sandbox API refusal with its reason and a hint for the model. */
    protected function sandboxError(ApiResult $result, ?string $what = null): ToolError
    {
        $error = ToolError::fromApi($result, $what);
        $reason = is_string($result->get('reason')) ? $result->get('reason') : null;
        $status = is_string($result->get('status')) ? $result->get('status') : null;
        $details = $error->details + array_filter([
            'reason' => $reason,
            'sandbox_status' => $status,
            'limits' => is_array($result->get('limits')) ? $result->get('limits') : null,
            'usage' => is_array($result->get('usage')) ? $result->get('usage') : null,
        ], fn ($v) => $v !== null);

        $hint = match (true) {
            $reason === 'not_running' && $status === 'paused' => 'The sandbox is paused: sandbox_lifecycle with action resume continues it.',
            $reason === 'not_running' && $status === 'pending' => 'The sandbox is still starting: sandbox_get shows when it runs.',
            $reason === 'not_running' => 'The sandbox has ended: create a new one with sandbox_create.',
            $reason !== null && str_starts_with($reason, 'quota_') => 'A sandbox quota is reached: sandbox_quota shows the limits; sandbox_destroy frees sandboxes you no longer need.',
            $reason === 'not_found', $result->status === 404 => 'sandboxes_list shows the sandboxes this connection can see.',
            $reason === 'class_unavailable', $reason === 'region_unavailable' => 'sandbox_quota lists the regions that take sandboxes and their classes.',
            default => null,
        };

        return new ToolError($error->getMessage().($hint ? "\n".$hint : ''), $details);
    }

    /** The fields of a sandbox an agent needs (SandboxResource is larger). */
    protected static function sandboxSummary(array $s): array
    {
        $network = is_array($s['network'] ?? null) ? $s['network'] : [];
        $res = is_array($s['resources'] ?? null) ? $s['resources'] : [];

        return array_filter([
            'id' => $s['id'] ?? null,
            'status' => $s['status'] ?? null,
            'region' => $s['region']['name'] ?? $s['region']['id'] ?? null,
            'runtime' => isset($s['runtime']) ? trim($s['runtime'].' '.($s['runtime_version'] ?? '')) : null,
            'image' => $s['image'] ?? null,
            'sandbox_class' => $s['sandbox_class'] ?? null,
            'isolation' => $s['isolation'] ?? null,
            'cpu_m' => $res['cpu_m'] ?? null,
            'memory_mb' => $res['memory_mb'] ?? null,
            'disk_mb' => $res['disk_mb'] ?? null,
            'network' => ($network['mode'] ?? 'none') === 'none' ? 'none' : ['egress_allow' => array_values((array) ($network['egress_allow'] ?? []))],
            'read_only_root' => $s['read_only_root'] ?? null,
            'env_keys' => $s['env_keys'] ?? null,
            'metadata' => is_array($s['metadata'] ?? null) && $s['metadata'] !== [] ? $s['metadata'] : null,
            'expires_at' => $s['expires_at'] ?? null,
            'idle_expires_at' => $s['idle_expires_at'] ?? null,
            'started_at' => $s['started_at'] ?? null,
            'ended_at' => $s['ended_at'] ?? null,
            'end_reason' => $s['end_reason'] ?? null,
            'error' => $s['error'] ?? null,
            'from_pool' => ! empty($s['from_pool']) ? true : null,
            'start_ms' => $s['start_ms'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * stdout / stderr (or their *_base64 forms) cut at the tool output cap, with the flags of
     * what was cut. The API cuts at 10 MiB already (truncated); the cap keeps a model's context.
     */
    protected static function capOutput(array $data, array $fields = ['stdout', 'stderr']): array
    {
        $max = max(1024, (int) config('mcp.tools.max_file_bytes', 512 * 1024));
        $cut = [];

        foreach ($fields as $field) {
            foreach ([$field, $field.'_base64'] as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && strlen($data[$key]) > $max) {
                    $start = strlen($data[$key]) - $max;
                    if ($key !== $field) {
                        $start += (4 - $start % 4) % 4; // whole base64 quanta: the tail still decodes
                    }
                    $data[$key] = $key === $field ? mb_strcut($data[$key], $start, null, 'UTF-8') : substr($data[$key], $start);
                    $cut[] = $key;
                }
            }
        }
        if ($cut !== []) {
            $data['output_cut'] = $cut; // only the last bytes of these are shown
        }

        return $data;
    }

    /** Polls a sandbox until it runs or ends, with progress; returns it as last read. */
    protected function waitForSandbox(ToolContext $context, array $sandbox, int $deadline): array
    {
        $step = 0;
        while (($sandbox['status'] ?? 'pending') === 'pending') {
            $context->progress(++$step, null, 'Sandbox '.($sandbox['id'] ?? '').' is starting…');
            if (time() >= $deadline || ! $context->pause()) {
                break;
            }
            $sandbox = (array) $this->sandboxCall($context, 'GET', self::sandboxPath((string) $sandbox['id']), [], [], 'Reading the sandbox')->data();
        }

        return $sandbox;
    }
}
