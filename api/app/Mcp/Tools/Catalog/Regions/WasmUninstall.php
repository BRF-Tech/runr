<?php

namespace App\Mcp\Tools\Catalog\Regions;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\UsesWasm;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class WasmUninstall extends Tool
{
    use UsesWasm;

    public function name(): string
    {
        return 'wasm_uninstall';
    }

    public function title(): string
    {
        return 'Remove WebAssembly support from a region';
    }

    public function description(): string
    {
        return 'Removes WebAssembly support from a region\'s nodes (refused while Wasm pods run there; deployments of Wasm projects stop working). Queued; with wait (default true) the tool follows it to the end. Needs confirm: true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'region' => Schema::string('Region id or name.', ['minLength' => 1, 'maxLength' => 64]),
            'confirm' => Schema::confirm('removing WebAssembly support from the region'),
            'wait' => Schema::boolean('Follow the removal until it is done (default true).', true),
            'timeout_seconds' => Schema::integer('Longest wait (default and cap: the server maximum).', 1, 3600),
        ], ['region', 'confirm']);
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
        return self::destructive();
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

        $context->progress(0, null, 'Queueing the removal…');
        $queued = $context->api()->delete(InternalApi::path('knative-regions', $regionId, 'wasm'));
        if (! $queued->ok()) {
            throw ToolError::fromApi($queued, 'WebAssembly support was not removed');
        }

        if (! ($arguments['wait'] ?? true)) {
            return ToolResult::ok(['status' => 'uninstalling'], 'The removal is queued; wasm_status follows it.');
        }

        $state = self::wasmSummary($this->waitForWasm($context, $regionId, $deadline));

        return match ($state['status'] ?? null) {
            'not_installed' => ToolResult::ok($state, 'WebAssembly support is removed from the region.'),
            'error' => ToolResult::error('The removal failed: '.($state['message'] ?? 'no details'), $state),
            default => ToolResult::ok($state, 'WebAssembly support is '.($state['status'] ?? 'unknown').'; wasm_status follows it.'),
        };
    }
}
