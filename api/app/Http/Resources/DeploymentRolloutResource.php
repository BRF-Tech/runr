<?php

namespace App\Http\Resources;

use App\Models\DeploymentRollout;
use App\Models\ProjectEnvironment;
use App\Services\Environments\EnvironmentScope;
use App\Services\Rollouts\RolloutMetrics;
use App\Services\Rollouts\RolloutTraffic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A blue-green / canary rollout (docs/deploy-strategies.md): the traffic split,
 * the canary step or the blue-green health checks, the canary's metrics against its thresholds,
 * the test URLs, what happened and what can be done now.
 *
 * @mixin DeploymentRollout
 */
class DeploymentRolloutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DeploymentRollout $rollout */
        $rollout = $this->resource;
        // The workload it rolls out: the project's own, or an environment's (its host, its region)
        $project = $rollout->workload();
        $environment = EnvironmentScope::of($project);
        $options = (array) ($rollout->options ?? []);
        $canary = $rollout->strategy === DeploymentRollout::CANARY;
        $open = $rollout->isOpen();
        $percent = (int) $rollout->traffic_percent;

        return [
            'id' => $rollout->id,
            'project_id' => $rollout->project_id,
            'deployment_id' => $rollout->deployment_id,
            'stable_deployment_id' => $rollout->stable_deployment_id,
            // the environment it rolls out (production: the project's own workload)
            'environment_id' => $environment?->getKey(),
            'environment' => $environment->name ?? ProjectEnvironment::PRIMARY_NAME,
            /** @var "blue_green"|"canary" */
            'strategy' => $rollout->strategy,
            /** @var "pending"|"progressing"|"waiting_promotion"|"promoted"|"aborted"|"rolled_back"|"failed" */
            'status' => $rollout->status,
            'open' => $open,
            'traffic' => ['candidate' => $percent, 'stable' => 100 - $percent],
            'revision' => $rollout->revision,
            'stable_revision' => $rollout->stable_revision,
            'tag' => $rollout->tag,
            'test_url' => $rollout->holdsTraffic() ? RolloutTraffic::tagUrl($project, $rollout->tag) : null,
            'previous_url' => $rollout->keepsPrevious() ? RolloutTraffic::tagUrl($project, RolloutTraffic::PREVIOUS_TAG) : null,
            /** @var array<string, mixed> the strategy's options the rollout runs with */
            'options' => $options,
            /** @var array{index: int, count: int, percent: int, next_percent: int|null, started_at: string|null, ends_at: string|null}|null */
            'step' => $canary ? $this->step($rollout, $options) : null,
            /** @var array{path: string, passes: int, required: int, last_status: int|null, last_error: string|null, checked_at: string|null, deadline_at: string|null}|null */
            'health' => $rollout->strategy === DeploymentRollout::BLUE_GREEN ? $this->health($rollout) : null,
            /** @var array{tracing: bool, source: string|null, revision: string|null, error_rate: float|null, p95_ms: float|null, volume: float, window_minutes: int, measured_at: string|null, error_rate_threshold: float|null, p95_threshold_ms: int|null}|null */
            'metrics' => $canary ? $this->metrics($rollout, $options) : null,
            /** @var string|null */
            'keep_until' => $rollout->keep_until?->toIso8601String(),
            /** @var string|null */
            'promoted_at' => $rollout->promoted_at?->toIso8601String(),
            /** @var string|null */
            'finished_at' => $rollout->finished_at?->toIso8601String(),
            /** @var string|null */
            'released_at' => $rollout->released_at?->toIso8601String(),
            /** @var string|null */
            'next_check_at' => $rollout->next_check_at?->toIso8601String(),
            'reason' => $rollout->reason,
            /** @var list<array{at: string, type: string, message: string, traffic?: int, actor?: string}> */
            'events' => (array) ($rollout->events ?? []),
            'can' => [
                'promote' => in_array($rollout->status, [DeploymentRollout::PROGRESSING, DeploymentRollout::WAITING_PROMOTION], true),
                'abort' => $open,
                'rollback' => $rollout->keepsPrevious(),
            ],
            'created_by' => $rollout->created_by,
            /** @var string|null */
            'created_at' => $rollout->created_at?->toIso8601String(),
            /** @var string|null */
            'updated_at' => $rollout->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function step(DeploymentRollout $rollout, array $options): ?array
    {
        $index = $rollout->step_index;
        if ($index === null) {
            return null;
        }

        $steps = $rollout->steps();
        $interval = max(1, (int) ($options['interval_minutes'] ?? 5));

        return [
            'index' => $index,
            'count' => count($steps),
            'percent' => $steps[$index] ?? 100,
            'next_percent' => $rollout->isOpen() ? ($steps[$index + 1] ?? 100) : null,
            'started_at' => $rollout->step_started_at?->toIso8601String(),
            'ends_at' => $rollout->step_started_at?->copy()->addMinutes($interval)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null the checks of a blue-green rollout that promotes itself with a health path */
    private function health(DeploymentRollout $rollout): ?array
    {
        $health = (array) ($rollout->health ?? []);
        if (empty($health['path'])) {
            return null;
        }

        return [
            'path' => $health['path'],
            'passes' => (int) ($health['passes'] ?? 0),
            'required' => (int) ($health['required'] ?? 0),
            'last_status' => $health['last_status'] ?? null,
            'last_error' => $health['last_error'] ?? null,
            'checked_at' => $health['checked_at'] ?? null,
            'deadline_at' => $health['deadline_at'] ?? null,
        ];
    }

    /** @return array<string, mixed>|null the last measurement of a canary step, with the thresholds it is judged by */
    private function metrics(DeploymentRollout $rollout, array $options): ?array
    {
        $metrics = (array) ($rollout->metrics ?? []);
        if ($metrics === []) {
            return null;
        }

        return [
            'tracing' => (bool) ($metrics['tracing'] ?? false),
            // where the new revision's numbers come from (RolloutMetrics): span_metrics, knative, or
            // null — none yet, the step moves on time only; a measurement of an earlier release
            // without it came from the project's span metrics
            'source' => array_key_exists('source', $metrics)
                ? $metrics['source']
                : (($metrics['tracing'] ?? false) && ($metrics['measured_at'] ?? null) !== null ? RolloutMetrics::SOURCE_SPANS : null),
            'revision' => $metrics['revision'] ?? $rollout->revision,
            'error_rate' => $metrics['error_rate'] ?? null,
            'p95_ms' => $metrics['p95_ms'] ?? null,
            'volume' => $metrics['volume'] ?? 0,
            'window_minutes' => (int) ($metrics['window_minutes'] ?? 0),
            'measured_at' => $metrics['measured_at'] ?? null,
            'error_rate_threshold' => $options['error_rate_threshold'] ?? null,
            'p95_threshold_ms' => $options['p95_threshold_ms'] ?? null,
        ];
    }
}
