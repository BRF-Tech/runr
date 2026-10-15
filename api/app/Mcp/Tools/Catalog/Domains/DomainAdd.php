<?php

namespace App\Mcp\Tools\Catalog\Domains;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsDomains;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class DomainAdd extends Tool
{
    use ReadsDomains;

    public function name(): string
    {
        return 'domain_add';
    }

    public function title(): string
    {
        return 'Add a custom domain';
    }

    public function description(): string
    {
        return 'Adds a custom domain (host name) to a project. The answer names a TXT record that proves ownership and the CNAME / A records that route the host to the region: the user creates them at their DNS provider, then domain_verify activates the domain.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'domain' => Schema::string('Host name, e.g. "api.example.com" (no scheme, no path).', ['minLength' => 3, 'maxLength' => 253, 'pattern' => '^[A-Za-z0-9.-]+$']),
        ], ['project', 'domain']);
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
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'domains'), ['domain' => (string) $arguments['domain']]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The domain was not added');
        }

        $domain = self::domainSummary((array) $result->data());

        return ToolResult::ok(['domain' => $domain], sprintf(
            'Added %s. Create the TXT record %s = %s and the DNS records listed, then call domain_verify.',
            $domain['domain'] ?? $arguments['domain'],
            $domain['verification']['name'] ?? '?',
            $domain['verification']['value'] ?? '?',
        ));
    }
}
