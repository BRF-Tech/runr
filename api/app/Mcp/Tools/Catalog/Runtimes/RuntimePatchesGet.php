<?php

namespace App\Mcp\Tools\Catalog\Runtimes;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class RuntimePatchesGet extends Tool
{
    public function name(): string
    {
        return 'runtime_patches_get';
    }

    public function title(): string
    {
        return 'Show the runtime patches of a project';
    }

    public function description(): string
    {
        return 'Shows the runtime patch state of a project (up_to_date, patch_available with the most urgent level, pinned, overdue past a critical / zero-day deadline), the patch it runs, its pin, and the newest patches of its runtime version with why each came: level (routine, security, critical, zero-day), reason, CVEs, link and deadline. runtime_patch_apply moves it to the newest patch, runtime_patch_pin pins it to one of these.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['project' => Schema::project()], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.view'];
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
        $projectId = $context->projectId((string) $arguments['project']);
        $context->setTarget('project', $projectId);

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'runtime-patches'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the runtime patches');
        }

        $data = (array) $result->data();
        $state = is_array($data['state'] ?? null) ? $data['state'] : null;
        $patches = array_values(array_map(fn ($p) => self::patchSummary((array) $p), array_filter((array) ($data['patches'] ?? []), 'is_array')));

        $details = array_filter([
            'state' => $state['state'] ?? null,
            'level' => $state['level'] ?? null,
            'deadline_at' => $state['deadline_at'] ?? null,
            'current' => $state['current']['tag'] ?? ($state['current']['digest'] ?? null),
            'latest' => $state['latest']['tag'] ?? null,
            'pin' => $state['pin'] ?? null,
            'auto_migrate' => $data['auto_migrate'] ?? null,
            'patches' => $patches,
            'migrations' => $data['migrations'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);

        if ($state === null) {
            return ToolResult::ok($details, 'No runtime patch concerns this project (CaaS, or its runtime version has no patches).');
        }

        return ToolResult::ok($details, sprintf('Runtime patches: %s%s; %d patch(es) listed.',
            $state['state'] ?? '?', ! empty($state['level']) ? " ({$state['level']})" : '', count($patches)));
    }

    /** The concise form of a patch. */
    public static function patchSummary(array $patch): array
    {
        return array_filter([
            'id' => $patch['id'] ?? null,
            'tag' => $patch['tag'] ?? null,
            'level' => $patch['level'] ?? null,
            'reason' => $patch['reason'] ?? null,
            'cves' => $patch['cves'] ?? null,
            'url' => $patch['url'] ?? null,
            'upstream' => $patch['upstream'] ?? null,
            'published_at' => $patch['published_at'] ?? null,
            'deadline_at' => $patch['deadline_at'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
