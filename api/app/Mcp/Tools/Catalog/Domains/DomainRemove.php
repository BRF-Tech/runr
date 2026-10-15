<?php

namespace App\Mcp\Tools\Catalog\Domains;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsDomains;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class DomainRemove extends Tool
{
    use ReadsDomains;

    public function name(): string
    {
        return 'domain_remove';
    }

    public function title(): string
    {
        return 'Remove a custom domain';
    }

    public function description(): string
    {
        return 'Removes a custom domain from a project, with its routes, certificate and TLS secret in the region; the host stops reaching the project. Ask the user first and pass confirm true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'domain' => Schema::string('The domain\'s host name or id.', ['minLength' => 1, 'maxLength' => 253]),
            'confirm' => Schema::confirm('removing the domain'),
        ], ['project', 'domain', 'confirm']);
    }

    public function permissions(): array
    {
        return ['domains.manage'];
    }

    public function group(): string
    {
        return 'domains';
    }

    public function annotations(): array
    {
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $domain = $this->findDomain($context, $projectId, (string) $arguments['domain']);

        $result = $context->api()->delete(InternalApi::path('projects', $projectId, 'domains', (string) $domain['id']));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The domain was not removed');
        }

        return ToolResult::ok(['removed' => ['id' => $domain['id'], 'domain' => $domain['domain'] ?? null]], ($domain['domain'] ?? 'The domain').' removed.');
    }
}
