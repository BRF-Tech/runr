<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxesList extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandboxes_list';
    }

    public function title(): string
    {
        return 'List sandboxes';
    }

    public function description(): string
    {
        return 'Lists the sandboxes of the organization — isolated pods (Kata micro-VM or gVisor) for running untrusted code: id, status, region, runtime or image, class, size, network, expiry. Default: the active ones (pending, running, paused). Filter by status, region, runtime or your metadata labels.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'status' => Schema::enum(['active', 'running', 'paused', 'pending', 'ended', 'all'], 'Which sandboxes (default active).', 'active'),
            'region' => Schema::string('Only sandboxes in this region (region id).', ['maxLength' => 64]),
            'runtime' => Schema::string('Only sandboxes of this runtime (python, nodejs, …).', ['maxLength' => 32]),
            'metadata' => Schema::map(['type' => 'string', 'maxLength' => 1024], 'Only sandboxes whose metadata has these key/value pairs.'),
            'limit' => Schema::integer('At most this many (default 50).', 1, 200),
        ]);
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
        $query = array_filter([
            'status' => $arguments['status'] ?? null,
            'region' => $arguments['region'] ?? null,
            'runtime' => $arguments['runtime'] ?? null,
            'per_page' => (int) ($arguments['limit'] ?? 50),
        ], fn ($v) => $v !== null && $v !== '');
        foreach ((array) ($arguments['metadata'] ?? []) as $key => $value) {
            $query['metadata'][(string) $key] = (string) $value;
        }

        $result = $this->sandboxCall($context, 'GET', '/sandboxes', [], $query, 'Listing sandboxes');
        $sandboxes = array_map([self::class, 'sandboxSummary'], array_values(array_filter((array) $result->data(), 'is_array')));
        $total = (int) ($result->get('meta.total') ?? count($sandboxes));

        return ToolResult::ok(
            ['sandboxes' => $sandboxes, 'total' => $total],
            $total.' sandbox(es)'.($total > count($sandboxes) ? ', '.count($sandboxes).' shown' : '').'.',
        );
    }
}
