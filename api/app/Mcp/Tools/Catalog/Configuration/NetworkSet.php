<?php

namespace App\Mcp\Tools\Catalog\Configuration;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;
use App\Models\Project;

class NetworkSet extends Tool
{
    public function name(): string
    {
        return 'network_set';
    }

    public function title(): string
    {
        return 'Set a project\'s access mode';
    }

    public function description(): string
    {
        return 'Sets who reaches a project from outside the cluster: public (anyone, the default), allowlist (public, only from the IP addresses / CIDR ranges in allowed_cidrs) or internal (no public URL; other projects of the region call it on its internal URL). Takes effect at once, without a redeploy. Internal removes the project\'s custom domains and gateway route from the region. Ask the user before making a public project internal.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'access_mode' => Schema::enum(Project::ACCESS_MODES, 'public, allowlist or internal.'),
            'allowed_cidrs' => Schema::array(Schema::string('An IP address or CIDR range, e.g. "203.0.113.0/24".', ['maxLength' => 64]), 'Source ranges of access mode allowlist.', 64),
        ], ['project', 'access_mode']);
    }

    public function permissions(): array
    {
        return ['network.manage'];
    }

    public function group(): string
    {
        return 'configuration';
    }

    public function annotations(): array
    {
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $body = ['access_mode' => (string) $arguments['access_mode']];
        if (isset($arguments['allowed_cidrs'])) {
            $body['allowed_cidrs'] = array_values((array) $arguments['allowed_cidrs']);
        }

        $result = $context->api()->put(InternalApi::path('projects', $projectId, 'network'), $body);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The access mode was not changed');
        }

        return ToolResult::ok(['network' => $result->data()], 'Access mode '.$body['access_mode'].'.');
    }
}
