<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxQuota extends Tool
{
    use UsesSandboxes;

    /** Region fields an agent choosing where to create a sandbox needs. */
    private const REGION_FIELDS = [
        'id', 'name', 'enabled', 'effective_classes', 'effective_default_class', 'default_ttl_seconds',
        'max_ttl_seconds', 'max_cpu_m', 'max_memory_mb', 'max_disk_mb',
    ];

    public function name(): string
    {
        return 'sandbox_quota';
    }

    public function title(): string
    {
        return 'Sandbox quota and regions';
    }

    public function description(): string
    {
        return 'What sandboxes this organization may still create: its quota (sandboxes at once, CPU and memory together, sandbox seconds this month, allowed classes) with the current usage, and the regions that take sandboxes with their isolation classes, default class, TTL and size limits. Read it before sandbox_create when a create was refused.';
    }

    public function inputSchema(): array
    {
        return Schema::object();
    }

    public function permissions(): array
    {
        return ['sandboxes.view'];
    }

    public function group(): string
    {
        return 'sandboxes';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $quota = (array) $this->sandboxCall($context, 'GET', '/sandbox-quotas', [], [], 'Reading the sandbox quota')->data();
        $regions = (array) $this->sandboxCall($context, 'GET', '/sandbox-regions', [], [], 'Reading the sandbox regions')->data();

        $regions = array_values(array_map(
            fn (array $r) => array_intersect_key(['id' => $r['region']['id'] ?? null, 'name' => $r['region']['name'] ?? null] + $r, array_flip(self::REGION_FIELDS)),
            array_filter($regions, 'is_array'),
        ));

        $enabled = array_filter($regions, fn ($r) => ($r['enabled'] ?? true) !== false);

        return ToolResult::ok([
            'limits' => $quota['limits'] ?? null,
            'usage' => $quota['usage'] ?? null,
            'regions' => $regions,
        ], count($enabled).' region(s) take sandboxes.');
    }
}
