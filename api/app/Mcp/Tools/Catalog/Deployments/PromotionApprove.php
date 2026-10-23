<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class PromotionApprove extends Tool
{
    public function name(): string
    {
        return 'promotion_approve';
    }

    public function title(): string
    {
        return 'Approve or reject a promotion';
    }

    public function description(): string
    {
        return 'Decides a promotion that waits for approval: approve deploys it to its target environment (its deployment follows; deployment_status shows it), reject ends it. Without a promotion id it lists the promotions waiting for you. The requester cannot approve their own promotion.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'promotion' => Schema::string('Promotion id; omit to list the waiting ones.', ['maxLength' => 26]),
            'decision' => Schema::enum(['approve', 'reject'], 'What to do (default approve).', 'approve'),
            'comment' => Schema::string('Recorded with the decision.', ['maxLength' => 2000]),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['environments.approve'];
    }

    public function group(): string
    {
        return 'deployments';
    }

    public function annotations(): array
    {
        return self::destructive(false);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        if (empty($arguments['promotion'])) {
            $result = $context->api()->get(InternalApi::path('projects', $projectId, 'promotions'), ['status' => 'pending_approval']);
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'Listing the promotions');
            }
            $waiting = (array) $result->data();

            return ToolResult::ok(['promotions' => $waiting], count($waiting).' promotion(s) wait for approval.');
        }

        $decision = ($arguments['decision'] ?? 'approve') === 'reject' ? 'reject' : 'approve';
        $result = $context->api()->post(
            InternalApi::path('projects', $projectId, 'promotions', (string) $arguments['promotion'], $decision),
            array_filter(['comment' => $arguments['comment'] ?? null]),
        );
        if (! $result->ok()) {
            throw ToolError::fromApi($result, $decision === 'approve' ? 'Approving the promotion' : 'Rejecting the promotion');
        }
        $promotion = (array) $result->data();
        $context->setTarget('project', $projectId);

        return ToolResult::ok(['promotion' => $promotion], $decision === 'approve'
            ? 'Approved: v'.($promotion['version']['number'] ?? '?').' goes to '.($promotion['target'] ?? '?').' ('.($promotion['status'] ?? '?').').'
            : 'Rejected.');
    }
}
