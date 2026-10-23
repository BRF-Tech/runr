<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class Promote extends Tool
{
    use WaitsForDeployments;

    public function name(): string
    {
        return 'promote';
    }

    public function title(): string
    {
        return 'Promote an environment\'s version';
    }

    public function description(): string
    {
        return 'Promotes the version an environment runs to the next environment of the pipeline (or the one named): the same code version and image, never built again, with the target\'s own configuration. dry_run shows what will change (code version, config differences — env var names only). Into an environment that requires approval (production by default) the promotion waits for an approver (promotion_approve); otherwise it deploys and, with wait, waits until it is live.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'from' => Schema::string('Environment whose live version is promoted, e.g. "staging".', ['maxLength' => 64]),
            'to' => Schema::string('Target environment; default: the next one in the pipeline.', ['maxLength' => 64]),
            'message' => Schema::string('Why (shown to the approver).', ['maxLength' => 2000]),
            'dry_run' => Schema::boolean('Only show what would change (default false).'),
            'wait' => Schema::boolean('Wait for the deployment when it starts right away (default true).', true),
            'timeout_seconds' => Schema::integer('Longest wait in seconds.', 1, 3600),
        ], ['project', 'from']);
    }

    public function permissions(): array
    {
        return ['environments.promote'];
    }

    public function group(): string
    {
        return 'deployments';
    }

    public function annotations(): array
    {
        return self::destructive(false);
    }

    public function streams(): bool
    {
        return true;
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $from = (string) $arguments['from'];
        $path = InternalApi::path('projects', $projectId, 'environments', $from, 'promote');
        $to = isset($arguments['to']) && $arguments['to'] !== '' ? (string) $arguments['to'] : null;

        if (! empty($arguments['dry_run'])) {
            $preview = $context->api()->get($path, array_filter(['to' => $to]));
            if (! $preview->ok()) {
                throw ToolError::fromApi($preview, 'Previewing the promotion');
            }
            $data = (array) $preview->data();

            return ToolResult::ok($data, $data['problem'] ?? sprintf(
                'Promoting %s to %s: v%s → v%s, %d config change(s)%s.',
                $data['source']['environment'] ?? $from,
                $data['target']['environment'] ?? '?',
                $data['code']['from']['number'] ?? '—',
                $data['code']['to']['number'] ?? '?',
                count((array) ($data['config'] ?? [])),
                ! empty($data['requires_approval']) ? '; it needs approval' : '',
            ));
        }

        $result = $context->api()->post($path, array_filter(['to' => $to, 'message' => $arguments['message'] ?? null]));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Promotion refused');
        }
        $promotion = (array) $result->data();
        $context->setTarget('project', $projectId);

        if (($promotion['status'] ?? null) === 'pending_approval') {
            return ToolResult::ok(['promotion' => $promotion], 'The promotion of v'.($promotion['version']['number'] ?? '?').' to '.($promotion['target'] ?? '?').' waits for an approver (promotion_approve, someone with environments.approve other than you).');
        }
        if (empty($promotion['deployment_id']) || ! ($arguments['wait'] ?? true)) {
            return ToolResult::ok(['promotion' => $promotion], 'Promotion '.($promotion['status'] ?? 'requested').($promotion['status_message'] ? ': '.$promotion['status_message'] : '').'.');
        }

        $deadline = time() + $context->maxWait(isset($arguments['timeout_seconds']) ? (int) $arguments['timeout_seconds'] : null);
        $deployment = $this->waitForDeployment($context, $projectId, ['id' => $promotion['deployment_id'], 'status' => 'pending'], $deadline);

        return match ($deployment['status'] ?? null) {
            'active' => ToolResult::ok(['promotion' => $promotion, 'deployment' => self::deploymentSummary($deployment)], 'v'.($promotion['version']['number'] ?? '?').' is live in '.($promotion['target'] ?? '?').'.'),
            'failed' => ToolResult::error('The promoted deployment failed'.(! empty($deployment['knative_message']) ? ': '.$deployment['knative_message'] : '').'. Call logs_tail for details.', ['promotion' => $promotion]),
            default => ToolResult::ok(['promotion' => $promotion, 'deployment' => self::deploymentSummary($deployment)], 'The promoted deployment is '.($deployment['status'] ?? 'pending').'; deployment_status follows it.'),
        };
    }
}
