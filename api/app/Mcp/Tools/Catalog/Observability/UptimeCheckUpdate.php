<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsUptime;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class UptimeCheckUpdate extends Tool
{
    use ReadsUptime;

    public function name(): string
    {
        return 'uptime_check_update';
    }

    public function title(): string
    {
        return 'Change an uptime check';
    }

    public function description(): string
    {
        return 'Changes an uptime check of a project (only the fields given; headers given replace the stored ones, omitted headers stay). A different target, method or expectation starts its state over.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'check' => Schema::string('The check: its id or exact name.', ['minLength' => 1, 'maxLength' => 100]),
        ] + self::uptimeProperties(), ['project', 'check']);
    }

    public function permissions(): array
    {
        return ['uptime.manage'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $body = array_diff_key($arguments, ['project' => true, 'check' => true]);
        if ($body === []) {
            throw new ToolError('Give at least one field of the check to change.');
        }
        $check = $this->findUptimeCheck($context, $projectId, (string) $arguments['check']);

        $result = $context->api()->put(InternalApi::path('projects', $projectId, 'uptime-checks', (string) $check['id']), $body);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The uptime check was not changed');
        }

        $updated = self::uptimeSummary((array) $result->data());

        return ToolResult::ok(['check' => $updated, 'changed' => array_keys($body)], 'Uptime check "'.($updated['name'] ?? '?').'" saved.');
    }
}
