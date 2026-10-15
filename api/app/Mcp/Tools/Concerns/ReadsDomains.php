<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/** Custom domains of a project: the list with its meta, one domain by id or host name. */
trait ReadsDomains
{
    /** @return array{data: list<array>, meta: array} */
    protected function domains(ToolContext $context, string $projectId): array
    {
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'domains'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the domains');
        }

        return [
            'data' => array_values(array_filter((array) $result->get('data', []), 'is_array')),
            'meta' => (array) $result->get('meta', []),
        ];
    }

    /** @throws ToolError */
    protected function findDomain(ToolContext $context, string $projectId, string $reference): array
    {
        $needle = mb_strtolower(trim($reference));
        foreach ($this->domains($context, $projectId)['data'] as $domain) {
            if ((string) ($domain['id'] ?? '') === $needle || mb_strtolower((string) ($domain['domain'] ?? '')) === $needle) {
                return $domain;
            }
        }

        throw new ToolError("The project has no custom domain \"{$reference}\". domains_list shows its domains.");
    }

    protected static function domainSummary(array $domain): array
    {
        return array_filter([
            'id' => $domain['id'] ?? null,
            'domain' => $domain['domain'] ?? null,
            'status' => $domain['status'] ?? null,
            'status_message' => $domain['status_message'] ?? null,
            'url' => $domain['url'] ?? null,
            'tls' => $domain['https_certificate'] ?? null,
            'tls_expires_at' => $domain['tls_expires_at'] ?? null,
            'verification' => $domain['verification'] ?? null,
            'dns_records' => $domain['dns_targets'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
