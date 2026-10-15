<?php

namespace App\Mcp\Tools\Catalog\Domains;

use App\Mcp\Tools\Concerns\ReadsDomains;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class DomainsList extends Tool
{
    use ReadsDomains;

    public function name(): string
    {
        return 'domains_list';
    }

    public function title(): string
    {
        return 'List custom domains';
    }

    public function description(): string
    {
        return 'Lists a project\'s custom domains with their status (pending verification, active, error), TLS and the DNS records to create, plus whether the project can have custom domains at all and its default host name.';
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
        return 'domains';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $domains = $this->domains($context, $context->projectId((string) $arguments['project']));
        $meta = $domains['meta'];

        return ToolResult::ok(array_filter([
            'domains' => array_map([self::class, 'domainSummary'], $domains['data']),
            'eligible' => $meta['eligible'] ?? null,
            'reason' => $meta['reason'] ?? null,
            'default_host' => $meta['default_host'] ?? null,
            'dns_targets' => $meta['dns_targets'] ?? null,
        ], fn ($v) => $v !== null), count($domains['data']).' custom domain(s).');
    }
}
