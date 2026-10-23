<?php

namespace App\Mcp\Tools\Catalog\Triggers;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsTriggers;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class TriggerUpdate extends Tool
{
    use ReadsTriggers;

    public function name(): string
    {
        return 'trigger_update';
    }

    public function title(): string
    {
        return 'Change a trigger';
    }

    public function description(): string
    {
        return 'Changes a trigger of a project: rename it, enable or disable it, make it primary, change config keys (merged into the stored config; a key set to null is removed) or replace a write-only secret ("" removes it). The type cannot change — remove the trigger and add another. The change reaches the cluster with the next deploy. '.self::configHelp();
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'trigger' => self::triggerArgument(),
            'name' => Schema::string('New name: lower-case letters, digits and dashes.', ['maxLength' => 63, 'pattern' => '^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$']),
            'enabled' => Schema::boolean('Turn the trigger on or off.'),
            'primary' => Schema::boolean('true: make it the primary trigger (first in the list).'),
            'config' => self::configArgument('Config keys to change, merged into the stored config; nested objects (hmac, delivery) merge too, null removes a key.'),
            'secrets' => self::secretsArgument('Secrets to replace, name → value ("" removes one).'),
        ], ['project', 'trigger']);
    }

    public function permissions(): array
    {
        return ['projects.update'];
    }

    public function group(): string
    {
        return 'projects';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $reference = (string) $arguments['trigger'];

        $payload = [];
        if (! empty($arguments['name'])) {
            $payload['name'] = (string) $arguments['name'];
        }
        if (array_key_exists('enabled', $arguments)) {
            $payload['enabled'] = (bool) $arguments['enabled'];
        }
        if (! empty($arguments['primary'])) {
            $payload['position'] = 0;
        }
        if (isset($arguments['config'])) {
            // The API replaces the config: start from the stored one
            $current = $this->findTrigger($context, $projectId, $reference);
            $payload['config'] = (object) self::merge((array) ($current['config'] ?? []), (array) $arguments['config']);
            $reference = (string) ($current['id'] ?? $reference);
        }
        if (isset($arguments['secrets']) && (array) $arguments['secrets'] !== []) {
            $payload['secrets'] = array_map('strval', (array) $arguments['secrets']);
        }
        if ($payload === []) {
            throw new ToolError('Nothing to change: pass name, enabled, primary, config or secrets.');
        }

        $result = $context->api()->patch(InternalApi::path('projects', $projectId, 'triggers', $reference), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The trigger was not changed');
        }

        $trigger = self::triggerSummary((array) $result->data());
        $changed = array_keys(array_diff_key($payload, ['position' => 1]) + (isset($payload['position']) ? ['primary' => 1] : []));

        // what the change left outside RunR that RunR drops later (a cdc trigger's old slot)
        $cleanup = array_values(array_filter((array) $result->get('cleanup', []), 'is_array'));
        $notes = implode(' ', array_map(fn (array $c) => (string) ($c['message'] ?? ''), $cleanup));

        return ToolResult::ok(array_filter([
            'trigger' => $trigger,
            'changed' => $changed,
            'requires_deploy' => (bool) $result->get('requires_deploy', true),
            'cleanup' => $cleanup ?: null,
        ], fn ($v) => $v !== null), trim('Changed '.implode(', ', $changed).' of trigger '.($trigger['name'] ?? $reference).'. It takes effect with the next deploy. '.$notes));
    }

    /** $changes merged into $base: nested objects merge, null removes a key. */
    private static function merge(array $base, array $changes): array
    {
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($base[$key]);

                continue;
            }
            $nested = is_array($value) && $value !== [] && ! array_is_list($value);
            $baseNested = is_array($base[$key] ?? null) && $base[$key] !== [] && ! array_is_list($base[$key]);
            $base[$key] = $nested && $baseNested ? self::merge($base[$key], $value) : $value;
        }

        return $base;
    }
}
