<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * billing_fixed_request_decide (docs/billing.md#approving): the seller's decision on a pending
 * fixed resource request — approve (the allocation from today) or reject with a reason — through
 * POST /api/billing/fixed-requests/{fixedRequest}/approve | reject (billing.approve).
 */
class BillingFixedRequestDecide extends BillingTool
{
    public function name(): string
    {
        return 'billing_fixed_request_decide';
    }

    public function title(): string
    {
        return 'Approve or reject a fixed request';
    }

    public function description(): string
    {
        return 'Approves or rejects a pending fixed resource request (billing module). Approved, the allocation is active and billed from today (prorated by day) and replaces the project\'s previous one; rejected, the requester is told the reason (at least 3 characters). Needs billing.approve — the region seller\'s decision; billing_fixed_requests_list lists the pending ones.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'request' => Schema::string('The request id.', ['minLength' => 1, 'maxLength' => 64]),
            'decision' => Schema::enum(['approve', 'reject'], 'Approve or reject.'),
            'note' => Schema::string('approve: a note for the requester.', ['maxLength' => 1000]),
            'reason' => Schema::string('reject: why (required).', ['minLength' => 3, 'maxLength' => 1000]),
        ], ['request', 'decision']);
    }

    public function permissions(): array
    {
        return ['billing.approve'];
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $approve = $arguments['decision'] === 'approve';
        if (! $approve && ! isset($arguments['reason'])) {
            throw new ToolError('A rejection needs reason.');
        }
        if ($approve && isset($arguments['reason'])) {
            throw new ToolError('reason is for a rejection; an approval takes note.');
        }

        $result = $context->api()->post(
            InternalApi::path('billing', 'fixed-requests', (string) $arguments['request'], $approve ? 'approve' : 'reject'),
            $approve ? array_filter(['note' => $arguments['note'] ?? null]) : ['reason' => (string) $arguments['reason']],
        );
        if (! $result->ok()) {
            throw ToolError::fromApi($result, $approve ? 'The request was not approved' : 'The request was not rejected');
        }

        $request = (array) $result->data();

        return ToolResult::ok(['request' => $request], $approve
            ? sprintf('Approved: %s vCPU, %s GB for %s, active from %s at %s a month.', $request['vcpu'] ?? '?', $request['memory_gb'] ?? '?',
                (string) ($request['project']['name'] ?? ''), (string) ($request['active_from'] ?? 'today'), self::amount($request['monthly_price'] ?? 0, (string) ($request['currency'] ?? '')))
            : 'Rejected: '.($request['decision_reason'] ?? $arguments['reason']));
    }
}
