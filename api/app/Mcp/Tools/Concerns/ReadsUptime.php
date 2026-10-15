<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * Uptime checks of a project (docs/observability.md, "Uptime checks"): lookup by id or name, the
 * concise form, and the check fields create and update share.
 */
trait ReadsUptime
{
    /** @return list<array> GET /projects/{id}/uptime-checks */
    protected function uptimeChecks(ToolContext $context, string $projectId): array
    {
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'uptime-checks'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the uptime checks');
        }

        return array_values(array_filter((array) $result->data(), 'is_array'));
    }

    /**
     * A check by id or exact name.
     *
     * @throws ToolError
     */
    protected function findUptimeCheck(ToolContext $context, string $projectId, string $reference): array
    {
        $needle = mb_strtolower(trim($reference));
        foreach ($this->uptimeChecks($context, $projectId) as $check) {
            if (mb_strtolower((string) ($check['id'] ?? '')) === $needle || mb_strtolower((string) ($check['name'] ?? '')) === $needle) {
                return $check;
            }
        }

        throw new ToolError("The project has no uptime check \"{$reference}\". uptime_checks_list shows them.");
    }

    protected static function uptimeSummary(array $check): array
    {
        return array_filter([
            'id' => $check['id'] ?? null,
            'name' => $check['name'] ?? null,
            'target' => $check['url'] ?? $check['path'] ?? null,
            'method' => $check['method'] ?? null,
            'interval_seconds' => $check['interval_seconds'] ?? null,
            'expected_status' => $check['expected_status'] ?? null,
            'locations' => $check['locations'] ?? null,
            'is_enabled' => $check['is_enabled'] ?? null,
            'status' => $check['status'] ?? null,
            'uptime_24h' => $check['uptime_24h'] ?? null,
            'uptime_7d' => $check['uptime_7d'] ?? null,
            'uptime_30d' => $check['uptime_30d'] ?? null,
            'avg_response_ms_24h' => $check['avg_response_ms_24h'] ?? null,
            'last_checked_at' => $check['last_checked_at'] ?? null,
            'last_status_code' => $check['last_status_code'] ?? null,
            'last_response_ms' => $check['last_response_ms'] ?? null,
            'last_error' => $check['last_error'] ?? null,
            'header_names' => $check['header_names'] ?? null,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /** Schema properties of the check fields (create: name required by the caller). */
    protected static function uptimeProperties(): array
    {
        return [
            'name' => Schema::string('Name of the check.', ['minLength' => 1, 'maxLength' => 100]),
            'path' => Schema::string('Path on the project\'s endpoint (default /).', ['maxLength' => 1024, 'pattern' => '^/']),
            'url' => Schema::string('An absolute http(s) URL to probe instead of a path of the project (private addresses are refused).', ['maxLength' => 2048, 'pattern' => '^https?://']),
            'method' => Schema::enum(['GET', 'HEAD', 'POST'], 'HTTP method (default GET).'),
            'headers' => Schema::map(['type' => 'string', 'maxLength' => 2000], 'Request headers, name → value (stored encrypted, write-only).'),
            'interval_seconds' => Schema::integer('Seconds between two probes (default 60).', 30, 3600),
            'timeout_seconds' => Schema::integer('Probe timeout in seconds (default 10).', 1, 60),
            'expected_status' => Schema::string('Accepted status codes: 200, 200,204, 200-399 (default) or 2xx.', ['maxLength' => 64]),
            'body_regex' => Schema::string('Pattern the response body must match.', ['maxLength' => 500]),
            'locations' => Schema::enum(['control_plane', 'regions'], 'Who probes: the RunR API (default) or the agents of regions.'),
            'region_ids' => Schema::array(Schema::string('Region id.', ['minLength' => 26, 'maxLength' => 26]), 'Regions that probe (locations regions; empty: the project\'s region).', 20),
            'failure_threshold' => Schema::integer('Consecutive failures that mark the check down (default 3).', 1, 20),
            'recovery_threshold' => Schema::integer('Consecutive successes that mark it up again (default 2).', 1, 20),
            'is_enabled' => Schema::boolean('Probe it (default true).'),
            'show_on_status_page' => Schema::boolean('Show it on the organization\'s status page.'),
            'status_page_name' => Schema::string('Name on the status page.', ['maxLength' => 100]),
        ];
    }
}
