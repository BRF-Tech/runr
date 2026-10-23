<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * project_fixed_request (docs/billing.md#requesting): request fixed resources for a project in a
 * fixed region — or cancel its pending request — through POST
 * /api/projects/{project}/billing/fixed-requests and …/{fixedRequest}/cancel. The region's seller
 * approves (billing_fixed_request_decide).
 */
class ProjectFixedRequest extends BillingTool
{
    public function name(): string
    {
        return 'project_fixed_request';
    }

    public function title(): string
    {
        return 'Request fixed resources';
    }

    public function description(): string
    {
        return 'Requests fixed resources for a project in a fixed-price region (billing module): cpu_m millicores, memory_mb MiB and gpu_count GPUs, with an optional note for the approver. The project is billed the monthly price from the approval day and may not use more (every pod up to max_scale and its previews must fit, and a request smaller than what runs is refused). A change is a new request. cancel: a pending request\'s id withdraws it. For the project\'s owner, members with projects.billing on it and billing.manage holders; project_billing_quote shows the price first.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'cpu_m' => Schema::integer('CPU in millicores (1000 = 1 vCPU).', 100, 1000000),
            'memory_mb' => Schema::integer('Memory in MiB.', 128, 10000000),
            'gpu_count' => Schema::integer('GPUs.', 0, 64),
            'note' => Schema::string('A note for the approver.', ['maxLength' => 1000]),
            'cancel' => Schema::string('Withdraw this pending request (its id) instead.', ['maxLength' => 64]),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        if (isset($arguments['cancel'])) {
            foreach (['cpu_m', 'memory_mb', 'gpu_count', 'note'] as $key) {
                if (array_key_exists($key, $arguments)) {
                    throw new ToolError("Give either cancel or a request ({$key}), not both.");
                }
            }
            $result = $context->api()->post(InternalApi::path('projects', $projectId, 'billing', 'fixed-requests', (string) $arguments['cancel'], 'cancel'));
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'The request was not cancelled');
            }

            return ToolResult::ok(['request' => $result->data()], 'Request '.$arguments['cancel'].' cancelled.');
        }

        if (! isset($arguments['cpu_m'], $arguments['memory_mb'])) {
            throw new ToolError('A request needs cpu_m and memory_mb (or cancel: a pending request\'s id).');
        }

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'billing', 'fixed-requests'), array_filter([
            'cpu_m' => (int) $arguments['cpu_m'],
            'memory_mb' => (int) $arguments['memory_mb'],
            'gpu_count' => isset($arguments['gpu_count']) ? (int) $arguments['gpu_count'] : null,
            'note' => $arguments['note'] ?? null,
        ], fn ($v) => $v !== null));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The request was not made');
        }

        $request = (array) $result->data();

        return ToolResult::ok(['request' => $request], sprintf('Requested %s vCPU, %s GB%s at %s a month (request %s): it waits for the region seller\'s approval.',
            $request['vcpu'] ?? '?', $request['memory_gb'] ?? '?', ($request['gpu_count'] ?? 0) > 0 ? ', '.$request['gpu_count'].' GPU(s)' : '',
            self::amount($request['monthly_price'] ?? 0, (string) ($request['currency'] ?? '')), (string) ($request['id'] ?? '')));
    }
}
