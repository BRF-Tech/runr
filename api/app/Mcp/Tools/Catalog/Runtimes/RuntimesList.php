<?php

namespace App\Mcp\Tools\Catalog\Runtimes;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class RuntimesList extends Tool
{
    public function name(): string
    {
        return 'runtimes_list';
    }

    public function title(): string
    {
        return 'List runtimes';
    }

    public function description(): string
    {
        return 'Lists the runtimes code projects can use (Python, Node.js, Go, PHP and registered ones): id, versions, the triggers they support, run mode, dependency file and entry file. Optionally only those offered in a region. project_create takes the id.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'region' => Schema::string('Only runtimes offered in this region (region id).', ['maxLength' => 64]),
        ]);
    }

    public function permissions(): array
    {
        return [];
    }

    public function group(): string
    {
        return 'runtimes';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get('/runtimes', ['region' => $arguments['region'] ?? null]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result);
        }

        $runtimes = array_map([self::class, 'summary'], array_values(array_filter((array) $result->get('data', []), 'is_array')));

        return ToolResult::ok([
            'runtimes' => $runtimes,
            'can_register' => (bool) ($result->get('abilities.manage') || ($result->get('abilities.use_custom') && $result->get('custom_enabled'))),
        ], count($runtimes).' runtime(s): '.implode(', ', array_column($runtimes, 'id')).'.');
    }

    public static function summary(array $runtime): array
    {
        return array_filter([
            'id' => $runtime['id'] ?? null,
            'name' => $runtime['name'] ?? null,
            'description' => $runtime['description'] ?? null,
            'source' => $runtime['source'] ?? null,
            'run_mode' => $runtime['run_mode'] ?? null,
            'compiled' => $runtime['compiled'] ?? null,
            'triggers' => $runtime['triggers'] ?? null,
            'sandbox' => $runtime['sandbox'] ?? null,
            'entry_file' => $runtime['handler']['filename'] ?? null,
            'handler' => $runtime['handler']['function'] ?? null,
            'dependency_file' => $runtime['dependency_file'] ?? null,
            // WebAssembly runtimes (docs/wasm.md): the shim they need, e.g. "spin"
            'wasm' => $runtime['wasm'] ?? null,
            'versions' => array_values(array_map(fn ($v) => array_filter([
                'version' => $v['version'] ?? null,
                'default' => ! empty($v['is_default']) ? true : null,
                'eol_at' => $v['eol_at'] ?? null,
                'gpu' => ! empty($v['gpu_required']) ? true : null,
            ], fn ($x) => $x !== null), array_filter((array) ($runtime['versions'] ?? []), 'is_array'))),
            'enabled' => $runtime['is_enabled'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
