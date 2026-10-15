<?php

namespace App\Mcp\Tools\Catalog\Domains;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsDomains;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class DomainVerify extends Tool
{
    use ReadsDomains;

    public function name(): string
    {
        return 'domain_verify';
    }

    public function title(): string
    {
        return 'Verify a custom domain';
    }

    public function description(): string
    {
        return 'Checks the DNS records of a custom domain and activates it when they are in place (routing and TLS in the region). When verification fails the answer says which record does not match; DNS changes can take a while to propagate, so try again later.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'domain' => Schema::string('The domain\'s host name or id.', ['minLength' => 1, 'maxLength' => 253]),
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
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $domain = $this->findDomain($context, $projectId, (string) $arguments['domain']);

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'domains', (string) $domain['id'], 'verify'));
        $data = $result->get('data');

        // 422 with the domain: verification ran and did not pass yet — an answer, not a failure
        if ($result->status === 422 && is_array($data)) {
            return ToolResult::ok(['verified' => false, 'message' => $result->message(), 'domain' => self::domainSummary($data)], 'Not verified yet: '.$result->message());
        }
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Verification failed');
        }

        return ToolResult::ok(['verified' => true, 'domain' => self::domainSummary((array) $data)], ($domain['domain'] ?? 'The domain').' is active.');
    }
}
