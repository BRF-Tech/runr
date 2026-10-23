<?php

namespace App\Mcp\Tools\Catalog\Runtimes;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class RuntimePatchPin extends Tool
{
    public function name(): string
    {
        return 'runtime_patch_pin';
    }

    public function title(): string
    {
        return 'Pin or unpin a runtime patch';
    }

    public function description(): string
    {
        return 'Pins a project to one of the newest patches of its runtime version (by id or tag, see runtime_patches_get) and, unless deploy is false, redeploys the live version onto it; or, with unpin, lets it follow the newest patch again on its next deploy. A pin waits only until a critical or zero-day deadline: then RunR moves the project on.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'patch' => Schema::string('Patch id ("php-8.3-8.3.36-r1") or tag ("8.3.36-r1") to pin to.', ['maxLength' => 128]),
            'unpin' => Schema::boolean('Remove the pin instead.'),
            'deploy' => Schema::boolean('Redeploy onto the pinned patch now (default true).', true),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.deploy'];
    }

    public function group(): string
    {
        return 'runtimes';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $context->setTarget('project', $projectId);

        if (! empty($arguments['unpin'])) {
            $result = $context->api()->delete(InternalApi::path('projects', $projectId, 'runtime-patches', 'pin'));
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'Removing the pin refused');
            }

            return ToolResult::ok(['state' => $result->data()], 'Unpinned: the next deploy uses the newest runtime patch.');
        }

        $reference = trim((string) ($arguments['patch'] ?? ''));
        if ($reference === '') {
            throw new ToolError('Name the patch to pin to (patch), or set unpin.');
        }

        $result = $context->api()->put(InternalApi::path('projects', $projectId, 'runtime-patches', 'pin'), [
            'patch_id' => $this->patchId($context, $projectId, $reference),
            'deploy' => (bool) ($arguments['deploy'] ?? true),
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Pinning refused');
        }

        $migration = $result->get('migration');

        return ToolResult::ok(['state' => $result->data(), 'migration' => $migration], is_array($migration)
            ? 'Pinned; the live version is redeployed onto the patch (runtime_patches_get follows it).'
            : 'Pinned; the next deploy uses the pinned patch.');
    }

    /** A patch id from an id or a tag of the project's pinnable patches. */
    private function patchId(ToolContext $context, string $projectId, string $reference): string
    {
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'runtime-patches'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the runtime patches');
        }

        $patches = array_filter((array) $result->get('data.patches', []), 'is_array');
        foreach ($patches as $patch) {
            if (($patch['id'] ?? null) === $reference || ($patch['tag'] ?? null) === $reference) {
                return (string) $patch['id'];
            }
        }

        throw new ToolError("\"{$reference}\" is not one of the project's pinnable patches: ".(implode(', ', array_column($patches, 'tag')) ?: 'none').'.');
    }
}
