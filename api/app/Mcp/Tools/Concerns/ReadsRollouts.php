<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * Blue-green / canary rollouts (docs/deploy-strategies.md): the project's current
 * one (GET /projects/{project}/rollout) or a deployment's, and their concise form.
 */
trait ReadsRollouts
{
    /**
     * The newest rollout of the project's own workload, or of one of its environments
     * (docs/deploy-strategies.md#environments); null when it never rolled out.
     */
    protected function currentRollout(ToolContext $context, string $projectId, ?string $environment = null): ?array
    {
        $query = $environment !== null && trim($environment) !== '' ? ['environment' => trim($environment)] : [];
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'rollout'), $query);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the rollout');
        }

        $data = $result->data();

        return is_array($data) && $data !== [] ? $data : null;
    }

    /**
     * The rollout of the deployment $reference names, or the project's current one.
     *
     * @throws ToolError
     */
    protected function rollout(ToolContext $context, string $projectId, ?string $reference = null, ?string $environment = null): array
    {
        if ($reference !== null && trim($reference) !== '' && $reference !== 'current') {
            $result = $context->api()->get(InternalApi::path('projects', $projectId, 'deployments', strtolower(trim($reference)), 'rollout'));
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'Reading the rollout');
            }

            return (array) $result->data();
        }

        $current = $this->currentRollout($context, $projectId, $environment);
        if ($current === null) {
            throw new ToolError('The project has no rollout: it deploys directly. A blue-green or canary deploy strategy (project settings, runr.yaml deploy:) makes its deploys roll out.');
        }

        return $current;
    }

    /** The concise form of a rollout. */
    protected static function rolloutSummary(array $rollout): array
    {
        return array_filter([
            'id' => $rollout['id'] ?? null,
            'deployment_id' => $rollout['deployment_id'] ?? null,
            'environment' => $rollout['environment'] ?? null,
            'previous_deployment_id' => $rollout['stable_deployment_id'] ?? null,
            'strategy' => $rollout['strategy'] ?? null,
            'status' => $rollout['status'] ?? null,
            'traffic' => $rollout['traffic'] ?? null,
            'test_url' => $rollout['test_url'] ?? null,
            'previous_url' => $rollout['previous_url'] ?? null,
            'step' => $rollout['step'] ?? null,
            'health' => $rollout['health'] ?? null,
            'metrics' => $rollout['metrics'] ?? null,
            'keep_until' => $rollout['keep_until'] ?? null,
            'next_check_at' => $rollout['next_check_at'] ?? null,
            'reason' => $rollout['reason'] ?? null,
            'can' => $rollout['can'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** One line on where a rollout stands, for a tool's message. */
    protected static function rolloutLine(array $rollout): string
    {
        $strategy = ($rollout['strategy'] ?? '') === 'canary' ? 'Canary' : 'Blue-green';
        $traffic = (int) ($rollout['traffic']['candidate'] ?? 0);

        return match ($rollout['status'] ?? null) {
            'pending' => "{$strategy} rollout: the new revision is starting (0 % of the traffic).",
            'waiting_promotion' => "{$strategy} rollout: the new revision is ready at ".($rollout['test_url'] ?? 'its test URL').' with 0 % of the traffic and waits for deployment_promote (or deployment_abort).',
            'progressing' => "{$strategy} rollout in progress: {$traffic} % of the traffic on the new revision".(isset($rollout['step']['ends_at']) ? ', next step at '.$rollout['step']['ends_at'] : '').'.',
            'promoted' => "{$strategy} rollout promoted: all traffic on the new revision".(! empty($rollout['can']['rollback']) ? ', the previous one is kept warm until '.($rollout['keep_until'] ?? '?').' (deployment_abort rolls back)' : '').'.',
            'aborted' => "{$strategy} rollout aborted: ".($rollout['reason'] ?? 'the traffic stayed on the previous revision.'),
            'rolled_back' => "{$strategy} rollout rolled back: ".($rollout['reason'] ?? 'the traffic is on the previous revision.'),
            'failed' => "{$strategy} rollout failed: ".($rollout['reason'] ?? 'the traffic stayed on the previous revision.'),
            default => "{$strategy} rollout is ".($rollout['status'] ?? 'unknown').'.',
        };
    }
}
