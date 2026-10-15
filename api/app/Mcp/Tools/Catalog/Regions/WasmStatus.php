<?php

namespace App\Mcp\Tools\Catalog\Regions;

use App\Mcp\Tools\Concerns\UsesWasm;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class WasmStatus extends Tool
{
    use UsesWasm;

    public function name(): string
    {
        return 'wasm_status';
    }

    public function title(): string
    {
        return 'WebAssembly support of a region';
    }

    public function description(): string
    {
        return 'Whether a region runs WebAssembly functions (runwasi shims, Spin): status (not_installed, installing, ready, uninstalling, error), the installed shims and nodes, and — unless cluster is false — every node with whether it can take WebAssembly and why not, plus the Knative features still to enable. Wasm runtimes (runtimes_list, field wasm) create projects only in regions where this is ready.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'region' => Schema::string('Region id or name.', ['minLength' => 1, 'maxLength' => 64]),
            'cluster' => Schema::boolean('Ask the cluster for its nodes (default true; false answers from RunR\'s records only).', true),
        ], ['region']);
    }

    public function permissions(): array
    {
        return ['regions.view'];
    }

    public function group(): string
    {
        return 'regions';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $regionId = $this->regionId($context, (string) $arguments['region']);
        $context->setTarget('region', $regionId);

        $state = self::wasmSummary($this->wasmState($context, $regionId, (bool) ($arguments['cluster'] ?? true)));

        $eligible = isset($state['cluster']['nodes']) ? count(array_filter($state['cluster']['nodes'], fn ($n) => ! empty($n['eligible']))) : null;

        return ToolResult::ok($state, 'WebAssembly support: '.($state['status'] ?? 'unknown')
            .(! empty($state['shims']) ? ' ('.implode(', ', $state['shims']).')' : '')
            .($eligible !== null ? "; {$eligible} eligible node(s)" : '').'.');
    }
}
