<?php

namespace App\Mcp\Tools\Catalog\Regions;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\UsesWasm;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class WasmInstall extends Tool
{
    use UsesWasm;

    public function name(): string
    {
        return 'wasm_install';
    }

    public function title(): string
    {
        return 'Install WebAssembly support in a region';
    }

    public function description(): string
    {
        return 'Installs (or changes) WebAssembly support of a region: the runwasi shims (spin — what the Wasm runtimes need —, optionally wasmtime) on the eligible nodes, or on the nodes named. Installing restarts the container runtime once on every selected node, so ask the user first. The install is queued; with wait (default true) the tool follows it until ready or error, reporting each step. wasm_status lists the nodes and why one is not eligible.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'region' => Schema::string('Region id or name.', ['minLength' => 1, 'maxLength' => 64]),
            'shims' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['spin', 'wasmtime']], 'minItems' => 1, 'maxItems' => 2, 'uniqueItems' => true, 'description' => 'Shims to install (default ["spin"]).', 'default' => ['spin']],
            'nodes' => Schema::array(Schema::string('Node name.', ['minLength' => 1, 'maxLength' => 253, 'pattern' => '^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$']), 'Only these nodes (default: every eligible node).', 500),
            'wait' => Schema::boolean('Follow the install until it is ready or failed (default true).', true),
            'timeout_seconds' => Schema::integer('Longest wait (default and cap: the server maximum).', 1, 3600),
        ], ['region']);
    }

    public function permissions(): array
    {
        return ['sandbox.manage'];
    }

    public function group(): string
    {
        return 'regions';
    }

    public function annotations(): array
    {
        // Restarts the container runtime of the selected nodes
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function streams(): bool
    {
        return true;
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $regionId = $this->regionId($context, (string) $arguments['region']);
        $context->setTarget('region', $regionId);
        $deadline = time() + $context->maxWait(isset($arguments['timeout_seconds']) ? (int) $arguments['timeout_seconds'] : null);

        $body = ['shims' => array_values($arguments['shims'] ?? ['spin'])];
        if (! empty($arguments['nodes'])) {
            $body['nodes'] = array_values($arguments['nodes']);
        }

        $context->progress(0, null, 'Queueing the installation…');
        $queued = $context->api()->post(InternalApi::path('knative-regions', $regionId, 'wasm'), $body);
        if (! $queued->ok()) {
            throw ToolError::fromApi($queued, 'WebAssembly support was not installed');
        }

        if (! ($arguments['wait'] ?? true)) {
            return ToolResult::ok(['status' => 'installing', 'warning' => $queued->get('warning')], 'The installation is queued; wasm_status follows it.');
        }

        $state = self::wasmSummary($this->waitForWasm($context, $regionId, $deadline));

        return match ($state['status'] ?? null) {
            'ready' => ToolResult::ok($state, 'WebAssembly support is ready'.(! empty($state['nodes']) ? ' on '.implode(', ', (array) $state['nodes']) : '').'. Projects can use the Wasm runtimes in this region.'),
            'error' => ToolResult::error('The installation failed: '.($state['message'] ?? 'no details'), $state),
            'installing' => ToolResult::ok($state, 'Still installing; wasm_status follows it.'),
            default => ToolResult::ok($state, 'WebAssembly support is '.($state['status'] ?? 'unknown').'.'),
        };
    }
}
