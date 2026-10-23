<?php

namespace App\Mcp\Tools\Catalog\Regions;

use App\Mcp\Tools\Concerns\UsesCertManager;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class CertManagerStatus extends Tool
{
    use UsesCertManager;

    public function name(): string
    {
        return 'cert_manager_status';
    }

    public function title(): string
    {
        return 'cert-manager of a region';
    }

    public function description(): string
    {
        return 'Whether a region issues certificates for custom domains (TLS mode cert-manager): cert-manager status (not_installed, installing, ready, error), whether RunR installed it or reuses the cluster\'s own, the ClusterIssuer (acme: publicly trusted from Let\'s Encrypt; ca: the region CA, which clients must trust; own: the operator\'s) and why RunR chose it, and — when the mode is not available — why and what to do. With cluster (default true) also whether the issuer is Ready and the region CA exists. cert_manager_install sets it up.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'region' => Schema::string('Region id or name.', ['minLength' => 1, 'maxLength' => 64]),
            'cluster' => Schema::boolean('Ask the cluster for the issuer\'s state (default true; false answers from RunR\'s records only).', true),
        ], ['region']);
    }

    public function permissions(): array
    {
        return ['regions.view'];
    }

    public function group(): string
    {
        return 'regions';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $regionId = $this->regionId($context, (string) $arguments['region']);
        $context->setTarget('region', $regionId);

        $state = self::certManagerSummary($this->certManagerState($context, $regionId, (bool) ($arguments['cluster'] ?? true)));
        $domains = $state['custom_domains'] ?? [];

        return ToolResult::ok($state, 'cert-manager: '.($state['status'] ?? 'unknown')
            .(isset($state['issuer']) ? ", ClusterIssuer {$state['issuer']}".(isset($state['issuer_kind']) ? " ({$state['issuer_kind']})" : '') : '')
            .'. '.(($domains['cert_manager_mode_available'] ?? false)
                ? 'Custom domains can use TLS mode cert-manager.'
                : 'TLS mode cert-manager is not available: '.($domains['reason'] ?? 'see the status')));
    }
}
