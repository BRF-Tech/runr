<?php

namespace App\Mcp\Tools\Catalog\Security;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class SecurityReport extends Tool
{
    use WaitsForDeployments;

    private const SEVERITY_ORDER = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'negligible' => 4, 'unknown' => 5];

    public function name(): string
    {
        return 'security_report';
    }

    public function title(): string
    {
        return 'Supply-chain security report';
    }

    public function description(): string
    {
        return 'The software supply-chain report of a version\'s image (default: the newest version): SBOM and scan state, vulnerability counts by severity and what is fixable, the most severe findings (up to 20), the image signature, and whether the region\'s policy gate would block a deploy.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'version' => Schema::string('Version number ("3", "v3"), id or "latest" (default).', ['maxLength' => 64]),
            'findings' => Schema::integer('Most severe findings to list (default 20).', 0, 100),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['security.view'];
    }

    public function group(): string
    {
        return 'security';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $version = $this->resolveVersion($context, $projectId, $arguments['version'] ?? null);

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'versions', (string) $version['id'], 'security'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the security report');
        }

        $data = (array) $result->get('data', []);
        $report = is_array($data['report'] ?? null) ? $data['report'] : null;
        $decision = (array) ($data['decision'] ?? []);

        $findings = array_values(array_filter((array) ($report['findings'] ?? []), 'is_array'));
        usort($findings, fn ($a, $b) => (self::SEVERITY_ORDER[strtolower((string) ($a['severity'] ?? 'unknown'))] ?? 9) <=> (self::SEVERITY_ORDER[strtolower((string) ($b['severity'] ?? 'unknown'))] ?? 9));
        $findings = array_slice($findings, 0, (int) ($arguments['findings'] ?? 20));

        $structured = array_filter([
            'version' => array_filter([
                'number' => $data['version']['number'] ?? $version['number'] ?? null,
                'image' => $data['version']['image'] ?? null,
                'applicable' => $data['version']['applicable'] ?? null,
            ], fn ($v) => $v !== null),
            'report' => $report ? array_filter([
                'status' => $report['status'] ?? null,
                'sbom' => $report['sbom_status'] ?? null,
                'scan' => $report['scan_status'] ?? null,
                'signature' => $report['signature_status'] ?? null,
                'counts' => $report['counts'] ?? null,
                'fixable' => $report['fixable'] ?? null,
                'findings_total' => $report['findings_total'] ?? null,
                'packages' => $report['packages'] ?? null,
                'scanned_at' => $report['scanned_at'] ?? null,
                'error' => $report['error'] ?? null,
            ], fn ($v) => $v !== null) : null,
            'findings' => $findings ?: null,
            'policy' => array_filter([
                'applicable' => $decision['applicable'] ?? null,
                'blocked' => $decision['blocked'] ?? null,
                'overridden' => $decision['overridden'] ?? null,
                'violations' => $decision['violations'] ?? null,
            ], fn ($v) => $v !== null && $v !== []),
        ], fn ($v) => $v !== null && $v !== []);

        $counts = (array) ($report['counts'] ?? []);
        $summary = $report
            ? sprintf('Version %s: %s; critical %d, high %d.%s', $version['number'] ?? '?', $report['status'] ?? 'unknown', (int) ($counts['critical'] ?? 0), (int) ($counts['high'] ?? 0), ! empty($decision['blocked']) ? ' The policy gate blocks deploying it.' : '')
            : 'Version '.($version['number'] ?? '?').' has no security report yet.';

        return ToolResult::ok($structured, $summary);
    }
}
