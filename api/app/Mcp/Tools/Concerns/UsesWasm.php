<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * WebAssembly support of a region (docs/wasm.md) for the wasm tools: the status read through
 * GET /knative-regions/{id}/wasm and the wait for a queued install / uninstall.
 */
trait UsesWasm
{
    use ReadsProjects;

    /** Status of a region's WebAssembly support; $cluster adds the nodes as the cluster reports them. */
    protected function wasmState(ToolContext $context, string $regionId, bool $cluster = false): array
    {
        $result = $context->api()->get(InternalApi::path('knative-regions', $regionId, 'wasm'), ['cluster' => $cluster ? 1 : 0]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the WebAssembly support of the region');
        }

        return (array) $result->json;
    }

    /** Polls while the region is installing / uninstalling, with the step log as progress. */
    protected function waitForWasm(ToolContext $context, string $regionId, int $deadline): array
    {
        $state = $this->wasmState($context, $regionId);
        $step = 0;
        while (in_array($state['status'] ?? null, ['installing', 'uninstalling'], true)) {
            $context->progress(++$step, null, self::lastLine($state['message'] ?? null) ?? ucfirst((string) $state['status']).'…');
            if (time() >= $deadline || ! $context->pause()) {
                break;
            }
            $state = $this->wasmState($context, $regionId);
        }

        return $state;
    }

    /** The concise form of the status. */
    protected static function wasmSummary(array $state): array
    {
        $summary = array_filter([
            'status' => $state['status'] ?? null,
            'message' => self::lastLine($state['message'] ?? null),
            'shims' => $state['shims'] ?? null,
            'nodes' => $state['nodes'] ?? null,
            'available_shims' => isset($state['available_shims']) ? array_values(array_filter(array_map(fn ($s) => is_array($s) ? ($s['id'] ?? null) : null, (array) $state['available_shims']))) : null,
            'can_manage' => $state['can_manage'] ?? null,
            'warning' => $state['warning'] ?? null,
            'cluster_error' => $state['cluster_error'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);

        $cluster = is_array($state['cluster'] ?? null) ? $state['cluster'] : null;
        if ($cluster) {
            $summary['cluster'] = array_filter([
                'nodes' => array_map(fn (array $n) => array_filter([
                    'name' => $n['name'] ?? null,
                    'eligible' => $n['eligible'] ?? null,
                    'reason' => $n['reason'] ?? null,
                    'target' => $n['target'] ?? null,
                    'shims' => $n['shims'] ?? null,
                    'arch' => $n['arch'] ?? null,
                ], fn ($v) => $v !== null && $v !== []), array_values(array_filter((array) ($cluster['nodes'] ?? []), 'is_array'))),
                'runtime_classes' => $cluster['runtime_classes'] ?? null,
                'knative_features_to_enable' => $cluster['knative_features_to_enable'] ?? null,
            ], fn ($v) => $v !== null && $v !== []);
        }

        return $summary;
    }

    private static function lastLine(mixed $message): ?string
    {
        if (! is_string($message) || trim($message) === '') {
            return null;
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $message))));

        return $lines === [] ? null : mb_substr(end($lines), 0, 500);
    }
}
