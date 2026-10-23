<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;
use Modules\Billing\Models\FixedRequest;

/**
 * billing_fixed_requests_list (docs/billing.md#approving): the fixed resource requests and
 * allocations of the organization's projects, pending ones first, through GET
 * /api/billing/fixed-requests.
 */
class BillingFixedRequestsList extends BillingTool
{
    public function name(): string
    {
        return 'billing_fixed_requests_list';
    }

    public function title(): string
    {
        return 'Fixed resource requests';
    }

    public function description(): string
    {
        return 'Fixed resource requests and allocations of the organization\'s projects (billing module), pending first: project, region, CPU, memory and GPUs, monthly price, status (pending, approved, rejected, cancelled, ended), who asked and decided, and the days an approved one is active. Filters: status, project, region. Needs billing.view; billing_fixed_request_decide approves or rejects.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'status' => Schema::enum(FixedRequest::STATUSES, 'Only requests of this status.'),
            'project' => Schema::string('Only this project (id, slug or exact name).'),
            'region' => Schema::string('Only this region (id).', ['maxLength' => 64]),
            'limit' => Schema::integer('Requests to return (default 50).', 1, 200),
        ]);
    }

    public function permissions(): array
    {
        return ['billing.view'];
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get('billing/fixed-requests', array_filter([
            'status' => $arguments['status'] ?? null,
            'project' => isset($arguments['project']) ? $context->projectId((string) $arguments['project']) : null,
            'region' => $arguments['region'] ?? null,
            'per_page' => (int) ($arguments['limit'] ?? 50),
        ], fn ($v) => $v !== null));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the fixed requests');
        }

        $requests = array_values((array) ($result->get('data') ?? []));
        $pending = count(array_filter($requests, fn ($r) => ($r['status'] ?? null) === FixedRequest::PENDING));
        $active = count(array_filter($requests, fn ($r) => ($r['status'] ?? null) === FixedRequest::APPROVED && ($r['active_until'] ?? null) === null));

        return ToolResult::ok(['total' => (int) ($result->get('meta.total') ?? count($requests)), 'requests' => $requests],
            $requests === [] ? 'No fixed resource requests.' : sprintf('%d request(s): %d pending, %d active allocation(s).', count($requests), $pending, $active));
    }
}
