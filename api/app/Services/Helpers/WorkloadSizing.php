<?php

namespace App\Services\Helpers;

use App\Models\Deployment;
use App\Models\Project;
use App\Services\Ai\Gpu\GpuRequest;
use App\Services\Contracts\KnativeConstants;
use App\Services\GitDeploy\PreviewManifests;
use App\Support\Projects\ScaleSchedule;

/**
 * The effective size of a project's workload — its config with a deploy's overrides
 * (deploy_config: cpu, memory_mb, min_scale, max_scale, scale_to_zero) on top, the way
 * KnativeManifestTrait builds the manifests — and the most it can reach.
 *
 *  - of()            the size a deploy applies; KnativeDeployService stores it on the deployment
 *                    (deployments.sizing), so metering counts what ran, not today's config
 *  - ofDeployment()  a deployment's stored size; deployments applied before 0.5.8 have none and
 *                    count with their project's config and their own overrides (metering takes
 *                    the size of their 0.5.7 record of the day instead, UsageAggregator)
 *  - peak()          the worst case: every pod up to max(1, min_scale, max_scale) with the
 *                    queue-proxy and RuntimeClass overhead (a CronJob runs one pod at a time; a
 *                    queue trigger adds its consumer where it is charged), GPUs × pods — what a
 *                    fixed-region project must fit into its approved allocation (Billing module)
 *  - previewPeak()   the same for a preview environment: one scale-to-zero Knative Service of the
 *                    project's size, at most PreviewManifests::MAX_SCALE pods
 *
 * A timed warmer (scale_schedule) sizes the workload with its peak as min_scale
 * (ScaleSchedule::reservedMinScale): what admission counts and the fixed-region allocation must
 * hold; metering counts the warm containers of each stretch of time instead (UsageAggregator).
 *
 * @phpstan-type Sizing array{region_id: string|null, cpu_m: int, memory_mb: int, trigger_type: string,
 *     min_scale: int, max_scale: int, scale_to_zero: bool, gpu_count: int}
 * @phpstan-type Peak array{cpu_m: int, memory_mb: int, gpus: int, pods: int}
 */
final class WorkloadSizing
{
    /** Config fields that size a workload (gpu: the GPU request of one pod; scale_schedule: the timed warmer). */
    private const FIELDS = ['cpu', 'memory_mb', 'trigger_type', 'min_scale', 'max_scale', 'scale_to_zero', 'gpu', 'scale_schedule'];

    /**
     * The size of the project's workload: its stored config, then each layer on top (a requested
     * config change, a deploy's overrides). A null or empty value in a layer keeps the value below
     * it, except gpu (null removes the GPU request) and scale_schedule (null or [] turns the timed
     * warmer off).
     *
     * @param  array<string, mixed>  ...$layers
     * @return Sizing
     */
    public static function of(Project $project, array ...$layers): array
    {
        $stored = ResourceReservation::configOf($project);
        $merged = [];
        foreach (self::FIELDS as $field) {
            $merged[$field] = $stored->{$field} ?? null;
        }
        // What the trigger list deploys (schedule | queue | http, docs/triggers.md); a layer that
        // sets trigger_type (a single-trigger config change) still wins
        $planned = ResourceReservation::capacityTriggerOf($project);
        if ($planned !== null && $planned !== ResourceReservation::capacityClass(is_string($merged['trigger_type']) ? $merged['trigger_type'] : null)) {
            $merged['trigger_type'] = $planned;
        }
        foreach ($layers as $layer) {
            foreach (self::FIELDS as $field) {
                if (! array_key_exists($field, $layer)) {
                    continue;
                }
                $value = $layer[$field];
                if ($field === 'gpu' || $field === 'scale_schedule' || ($value !== null && $value !== '')) {
                    $merged[$field] = $value;
                }
            }
        }

        $defaults = InstanceSizes::defaultFor($project->service_type);
        $cpu = $merged['cpu'];
        $memory = $merged['memory_mb'];

        try {
            $gpus = GpuRequest::forProject($project, (object) ['gpu' => $merged['gpu']])->count ?? 0;
        } catch (\Throwable) {
            $gpus = 0;
        }

        return [
            'region_id' => $project->knative_region_id !== null ? (string) $project->knative_region_id : null,
            'cpu_m' => max(0, ResourceReservation::parseCpu(($cpu === null || $cpu === '') ? $defaults['cpu'] : $cpu)),
            'memory_mb' => max(0, (int) (($memory === null || $memory === '') ? $defaults['memory_mb'] : $memory)),
            'trigger_type' => is_string($merged['trigger_type']) && $merged['trigger_type'] !== '' ? $merged['trigger_type'] : 'http',
            'min_scale' => ScaleSchedule::reservedMinScale(['min_scale' => $merged['min_scale'], 'scale_schedule' => $merged['scale_schedule']]),
            'max_scale' => max(0, (int) $merged['max_scale']),
            'scale_to_zero' => self::truthy($merged['scale_to_zero']),
            'gpu_count' => max(0, (int) $gpus),
        ];
    }

    /**
     * The size a deployment was applied with; for one applied before deployments recorded it, its
     * project's current config with the deployment's own overrides.
     *
     * @return Sizing
     */
    public static function ofDeployment(Deployment $deployment, Project $project): array
    {
        $stored = $deployment->sizing;
        if (is_array($stored) && isset($stored['cpu_m'], $stored['memory_mb'])) {
            return [
                'region_id' => isset($stored['region_id']) ? (string) $stored['region_id'] : ($project->knative_region_id !== null ? (string) $project->knative_region_id : null),
                'cpu_m' => (int) $stored['cpu_m'],
                'memory_mb' => (int) $stored['memory_mb'],
                'trigger_type' => (string) ($stored['trigger_type'] ?? 'http'),
                'min_scale' => (int) ($stored['min_scale'] ?? 0),
                'max_scale' => (int) ($stored['max_scale'] ?? 0),
                'scale_to_zero' => self::truthy($stored['scale_to_zero'] ?? true),
                'gpu_count' => (int) ($stored['gpu_count'] ?? 0),
            ];
        }

        return self::of($project, (array) ($deployment->deploy_config ?? []));
    }

    /**
     * Whether the workload keeps pods running all the time (ResourceReservation::isAlwaysOn): a
     * Knative Service with scale to zero off or a min_scale of at least 1.
     *
     * @param  Sizing  $sizing
     */
    public static function isAlwaysOn(array $sizing): bool
    {
        return ResourceReservation::isAlwaysOn($sizing['trigger_type'], $sizing['min_scale'], $sizing['scale_to_zero']);
    }

    /**
     * Pods kept running by an always-on workload: max(1, min_scale).
     *
     * @param  Sizing  $sizing
     */
    public static function reservedPods(array $sizing): int
    {
        return max(1, $sizing['min_scale']);
    }

    /**
     * A Knative Service without max_scale can grow without bound (Knative's default): its worst
     * case is unknown.
     *
     * @param  Sizing  $sizing
     */
    public static function unbounded(array $sizing): bool
    {
        return ResourceReservation::runsKnativeService($sizing['trigger_type']) && $sizing['max_scale'] < 1;
    }

    /**
     * The most the workload can reserve: (size + queue-proxy + RuntimeClass overhead) × every pod
     * up to max(1, min_scale, max_scale); a CronJob 1 pod; a queue trigger adds its consumer
     * unless $consumer is false (a fixed allocation holds it only where queue consumers are
     * charged, Billing module QueueConsumerCharging).
     *
     * @param  Sizing  $sizing
     * @param  array{cpu_m?: int, memory_mb?: int}  $podOverhead  RuntimeClass overhead per pod
     * @return Peak
     */
    public static function peak(array $sizing, array $podOverhead = [], bool $consumer = true): array
    {
        $trigger = $sizing['trigger_type'];
        $pods = ResourceReservation::runsKnativeService($trigger)
            ? max(1, $sizing['min_scale'], $sizing['max_scale'])
            : 1;
        $pod = ResourceReservation::perPod($sizing['cpu_m'], $sizing['memory_mb'], $trigger, $podOverhead);

        $cpu = $pod['cpu_m'] * $pods;
        $memory = $pod['memory_mb'] * $pods;
        if ($trigger === 'queue' && $consumer) {
            $cpu += KnativeConstants::QUEUE_CONSUMER_CPU_M;
            $memory += KnativeConstants::QUEUE_CONSUMER_MEMORY_MB;
        }

        return ['cpu_m' => $cpu, 'memory_mb' => $memory, 'gpus' => $sizing['gpu_count'] * $pods, 'pods' => $pods];
    }

    /**
     * The most a preview environment of the project can reserve: an HTTP Knative Service of the
     * project's size, at most PreviewManifests::MAX_SCALE pods (fewer when max_scale is lower).
     *
     * @param  Sizing  $sizing
     * @param  array{cpu_m?: int, memory_mb?: int}  $podOverhead
     * @return Peak
     */
    public static function previewPeak(array $sizing, array $podOverhead = []): array
    {
        $pods = $sizing['max_scale'] > 0 ? min($sizing['max_scale'], PreviewManifests::MAX_SCALE) : PreviewManifests::MAX_SCALE;
        $pod = ResourceReservation::perPod($sizing['cpu_m'], $sizing['memory_mb'], 'http', $podOverhead);

        return ['cpu_m' => $pod['cpu_m'] * $pods, 'memory_mb' => $pod['memory_mb'] * $pods, 'gpus' => $sizing['gpu_count'] * $pods, 'pods' => $pods];
    }

    /**
     * @param  Peak  $a
     * @param  Peak  $b
     * @return Peak
     */
    public static function add(array $a, array $b): array
    {
        return [
            'cpu_m' => $a['cpu_m'] + $b['cpu_m'],
            'memory_mb' => $a['memory_mb'] + $b['memory_mb'],
            'gpus' => $a['gpus'] + $b['gpus'],
            'pods' => $a['pods'] + $b['pods'],
        ];
    }

    /** @return Peak */
    public static function none(): array
    {
        return ['cpu_m' => 0, 'memory_mb' => 0, 'gpus' => 0, 'pods' => 0];
    }

    /** A boolean setting that may arrive as "0" / "false" / 0; null is the column default (true). */
    private static function truthy(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
