<?php

namespace App\Services\Projects;

use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentProject;
use App\Services\Environments\EnvironmentScope;
use App\Services\Helpers\ResourceReservation;
use App\Services\KubernetesClient;
use App\Services\Rollouts\RolloutTraffic;
use App\Support\OrgTimezone;
use App\Support\Projects\ScaleSchedule;
use App\Support\SchemaColumns;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The timed warmer on the cluster (docs/scaling.md#timed-warmer): the count of a project's
 * schedule (config.scale_schedule, ScaleSchedule) goes onto the Knative PodAutoscaler of its
 * latest ready revision as autoscaling.knative.dev/min-scale.
 *
 * Measured on Kind: annotating the PodAutoscaler takes effect without a new revision or a restart,
 * survives Knative's reconciles and controller restarts, and 0 releases the containers within
 * about a second. A min-scale on the Revision does nothing (the PodAutoscaler keeps its own
 * annotations) and restarts the revision's pods — the Revision is never touched. A new revision
 * (every deploy) gets a fresh PodAutoscaler from its template: the deploy renders the value of the
 * moment there (KnativeManifestTrait) and afterReady() applies the value again once the revision is
 * ready (CheckDeploymentReadyJob).
 *
 *  - the schedule is the project's config: a saved change applies on the next run, no deploy
 *    needed; the crons run in the organization's time zone (OrgTimezone), read on every run;
 *    scale to zero and max_scale are those the live deployment was applied with
 *  - run() (every minute, ApplyWarmScalesJob): one list of the Knative Services and one of the
 *    PodAutoscalers per region, a patch only where the PodAutoscaler's value differs; offline,
 *    inactive and demo regions are skipped
 *  - a schedule turned off gives the PodAutoscaler the project's min_scale back once
 *  - projects.warm_scale_applied / _at: the count last applied and when, for the console; null
 *    while no warmer runs
 *  - deployments.sizing.warm_log: [unix time, min-scale] whenever the applied value changed (the
 *    first entry when the deploy rendered it) — metering counts the warm containers from it
 *    (UsageAggregator), what really ran rather than what the schedule says now
 *
 * Environments (docs/environments.md): each environment's workload runs the warmer of its own
 * configuration — the project's schedule, or its own (overrides.scale_schedule; [] for none) — on
 * its Knative Service <workload> in its region, for every revision that takes traffic there
 * (RolloutTraffic on the environment's view: a blue-green / canary rollout of the environment), in
 * the organization's time zone. What was last applied is recorded on the environment
 * (project_environments.warm_scale_applied / _at), which also lets a schedule turned off there
 * give the PodAutoscaler its min_scale back.
 */
class WarmScaleService
{
    public const ANNOTATION = 'autoscaling.knative.dev/min-scale';

    public const PA_API = 'autoscaling.internal.knative.dev/v1alpha1';

    public const PA_KIND = 'PodAutoscaler';

    /** Seconds of warm_log kept before the latest entry older than that (metering recalculates two days). */
    private const LOG_KEEP_SECONDS = 4 * 86400;

    /**
     * Apply the warmers of the current organization context.
     *
     * @return int PodAutoscalers patched
     */
    public function run(?CarbonInterface $now = null): int
    {
        $now ??= now();
        if (! SchemaColumns::has(new Project, 'warm_scale_applied')) {
            return 0; // the organization's schema has not been migrated yet
        }

        $timezone = OrgTimezone::get();
        $patched = 0;

        foreach ($this->workloads($this->candidates())->groupBy('knative_region_id') as $projects) {
            $region = $projects->first()->knativeRegion;
            if (! self::reachable($region)) {
                continue;
            }

            try {
                $patched += $this->applyInRegion($region, $projects, $now, $timezone);
            } catch (\Throwable $e) {
                Log::warning('Timed warmer: region skipped', ['region' => $region->name, 'error' => $e->getMessage()]);
            }
        }

        return $patched;
    }

    /**
     * After a deployment became ready (CheckDeploymentReadyJob): the new revision's PodAutoscaler
     * gets the value of now — time passed since the template was rendered. A project without a
     * warmer has its applied value cleared. Never fails the readiness check.
     *
     * @param  array<string, mixed>  $service  the Knative Service as the cluster returned it
     */
    public function afterReady(Project $project, Deployment $deployment, array $service): void
    {
        try {
            if (! SchemaColumns::has($project, 'warm_scale_applied')) {
                return;
            }
            // an environment's deployment: its workload, config and region (docs/environments.md)
            $project = DeployTarget::project($project, $deployment);

            $rows = self::runsKnativeService($deployment) ? ScaleSchedule::rows(ResourceReservation::configOf($project)) : null;
            if ($rows === null) {
                // the new revision runs with the template's (static) min-scale
                $this->record($project, null);

                return;
            }

            $region = $project->knativeRegion;
            // The revisions that take traffic (a blue-green / canary rollout splits it,
            // RolloutTraffic) and the new one, ready to take it
            $revisions = RolloutTraffic::revisionsWithTraffic($service);
            $latest = $service['status']['latestReadyRevisionName'] ?? null;
            if (is_string($latest) && $latest !== '' && ! in_array($latest, $revisions, true)) {
                $revisions[] = $latest;
            }
            if ($revisions === [] || ! $region || $region->is_demo || ! $region->kubeconfig) {
                return;
            }

            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = self::namespace($region);
            $desired = self::desired($rows, $deployment, now(), OrgTimezone::get());
            foreach ($revisions as $revision) {
                $autoscaler = $k8s->getResourceStatus(self::PA_API, self::PA_KIND, $revision, $namespace);
                if ($autoscaler) {
                    // without one yet the scheduled run applies it once the PodAutoscaler exists
                    $this->converge($k8s, $namespace, $project, $deployment, $revision, $autoscaler, $desired, $desired);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Timed warmer: applying after the deploy failed', ['project' => $project->slug, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The min-scale a deployment's PodAutoscaler gets from the schedule at $now: the schedule's
     * value, at least one with scale to zero off, never above max_scale (as the deployment was
     * applied with them).
     *
     * @param  list<array{cron: string, min_scale: int}>  $rows
     */
    public static function desired(array $rows, Deployment $deployment, CarbonInterface $now, string $timezone): int
    {
        return self::bounded($deployment, ScaleSchedule::valueAt($rows, $now, $timezone));
    }

    /**
     * The value the deploy renders into a new template, and the first warm_log entry of the
     * deployment (KnativeDeployService::recordSizing).
     *
     * @param  array<string, mixed>  $sizing  the deployment's sizing (WorkloadSizing)
     * @param  list<array{cron: string, min_scale: int}>  $rows
     */
    public static function renderedValue(array $sizing, array $rows, CarbonInterface $now, string $timezone): int
    {
        $value = max(ScaleSchedule::floor($sizing['scale_to_zero'] ?? true), ScaleSchedule::valueAt($rows, $now, $timezone));
        $max = (int) ($sizing['max_scale'] ?? 0);

        return $max > 0 ? min($value, $max) : $value;
    }

    /** The PodAutoscaler's min-scale annotation, null without one. */
    public static function currentMinScale(array $autoscaler): ?int
    {
        $value = $autoscaler['metadata']['annotations'][self::ANNOTATION] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The min-scale the warmer applied to a deployment over time (deployments.sizing.warm_log),
     * oldest first; null when it never applied one.
     *
     * @return list<array{0: int, 1: int}>|null
     */
    public static function logOf(Deployment $deployment): ?array
    {
        $log = is_array($deployment->sizing) ? ($deployment->sizing['warm_log'] ?? null) : null;
        if (! is_array($log) || $log === []) {
            return null;
        }

        $out = [];
        foreach ($log as $entry) {
            if (is_array($entry) && isset($entry[0], $entry[1]) && is_numeric($entry[0]) && is_numeric($entry[1])) {
                $out[] = [(int) $entry[0], max(0, (int) $entry[1])];
            }
        }
        usort($out, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $out === [] ? null : $out;
    }

    /**
     * The min-scale a deployment ran with over [$from, $to) by its warm_log: stretches
     * [start, end, count] (Unix seconds); before the first entry the deployment's own min-scale.
     *
     * @param  list<array{0: int, 1: int}>  $log
     * @return list<array{0: int, 1: int, 2: int}>
     */
    public static function appliedSegments(array $log, Deployment $deployment, CarbonInterface $from, CarbonInterface $to): array
    {
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        if ($toTs <= $fromTs) {
            return [];
        }

        $current = self::valueBeforeLog($deployment);
        $start = $fromTs;
        $out = [];
        foreach ($log as [$at, $value]) {
            if ($at <= $fromTs) {
                $current = $value;

                continue;
            }
            if ($at >= $toTs) {
                break;
            }
            if ($value !== $current) {
                $out[] = [$start, $at, $current];
                $start = $at;
                $current = $value;
            }
        }
        $out[] = [$start, $toTs, $current];

        return $out;
    }

    /**
     * The min-scale in effect at $at by the deployment's warm_log.
     *
     * @param  list<array{0: int, 1: int}>  $log
     */
    public static function appliedAt(array $log, Deployment $deployment, CarbonInterface $at): int
    {
        $ts = $at->getTimestamp();
        $value = self::valueBeforeLog($deployment);
        foreach ($log as [$entryAt, $entryValue]) {
            if ($entryAt > $ts) {
                break;
            }
            $value = $entryValue;
        }

        return $value;
    }

    /**
     * Projects that may have a warmer to apply: a schedule in their config, or a value applied
     * before (a schedule turned off gives the PodAutoscaler min_scale back) — on their own workload
     * or on one of their environments (a schedule of its own, or a value applied there).
     *
     * @return Collection<int, Project>
     */
    private function candidates(): Collection
    {
        $viaEnvironments = $this->environmentCandidates();

        return Project::query()
            ->whereNull('stopped_at')
            ->whereNotNull('knative_region_id')
            ->where(function ($q) use ($viaEnvironments) {
                $q->whereNotNull('warm_scale_applied')
                    ->orWhereHas('xaasConfig', fn ($c) => $c->whereNotNull('scale_schedule'))
                    ->orWhereHas('faasConfig', fn ($c) => $c->whereNotNull('scale_schedule'))
                    ->orWhereHas('containerConfig', fn ($c) => $c->whereNotNull('scale_schedule'));
                if ($viaEnvironments !== []) {
                    $q->orWhereIn('id', $viaEnvironments);
                }
            })
            ->with(['knativeRegion', 'xaasConfig', 'faasConfig', 'containerConfig'])
            ->get();
    }

    /**
     * Projects with an environment that has a timed warmer of its own or a value applied (its
     * overrides are encrypted: read here, not queried).
     *
     * @return list<string>
     */
    private function environmentCandidates(): array
    {
        if (! ProjectEnvironment::tablesReady()) {
            return [];
        }
        $applied = SchemaColumns::has(new ProjectEnvironment, 'warm_scale_applied');

        return ProjectEnvironment::query()->where('is_primary', false)->get()
            ->filter(fn (ProjectEnvironment $e) => ($applied && $e->getAttribute('warm_scale_applied') !== null)
                || array_key_exists('scale_schedule', $e->overrideValues()))
            ->pluck('project_id')->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    /** The value last applied to the workload $project stands for: the environment's, or the project's. */
    private static function appliedOf(Project $project): ?int
    {
        $environment = EnvironmentScope::of($project);
        $value = $environment !== null
            ? (SchemaColumns::has($environment, 'warm_scale_applied') ? $environment->getAttribute('warm_scale_applied') : null)
            : $project->getAttribute('warm_scale_applied');

        return $value === null ? null : (int) $value;
    }

    /**
     * The workloads the warmers run on: each project's own and its environments' (EnvironmentProject:
     * the environment's config with its overrides, its region, its deployments).
     *
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, Project>
     */
    private function workloads(Collection $projects): Collection
    {
        if (! ProjectEnvironment::tablesReady()) {
            return $projects;
        }

        $workloads = new Collection;
        foreach ($projects as $project) {
            $workloads->push($project);
            foreach (ProjectEnvironment::query()->where('project_id', $project->id)->where('is_primary', false)->get() as $environment) {
                $view = EnvironmentProject::of($project, $environment);
                if ($view->knative_region_id !== null) {
                    $workloads->push($view);
                }
            }
        }

        return $workloads;
    }

    /** @param  Collection<int, Project>  $projects */
    private function applyInRegion(KnativeRegion $region, Collection $projects, CarbonInterface $now, string $timezone): int
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $namespace = self::namespace($region);

        $services = $k8s->listResources('serving.knative.dev/v1', 'Service', $namespace);
        $autoscalers = $k8s->listResources(self::PA_API, self::PA_KIND, $namespace);
        if ($services === null || $autoscalers === null) {
            Log::warning('Timed warmer: the region did not list its Knative Services / PodAutoscalers', ['region' => $region->name]);

            return 0;
        }

        $revisions = [];
        foreach ($services['items'] ?? [] as $item) {
            // the revisions that take traffic: both of a blue-green / canary rollout
            $revisions[$item['metadata']['name'] ?? ''] = RolloutTraffic::revisionsWithTraffic($item);
        }
        $byName = [];
        foreach ($autoscalers['items'] ?? [] as $item) {
            $byName[$item['metadata']['name'] ?? ''] = $item;
        }

        $patched = 0;
        foreach ($projects as $project) {
            /** @var Deployment|null $live */
            $live = $project->deployments()->where('status', 'active')->latest('created_at')->first();
            if (! $live || ! self::runsKnativeService($live)) {
                // nothing runs (or a CronJob): nothing to warm
                $this->record($project, null);

                continue;
            }

            $config = ResourceReservation::configOf($project);
            $rows = ScaleSchedule::rows($config);
            if ($rows === null && self::appliedOf($project) === null) {
                // no warmer here, and none to let go (a project listed for an environment's warmer)
                continue;
            }
            foreach ($revisions[$project->slug] ?? [] as $revision) {
                $autoscaler = $byName[$revision] ?? null;
                if (! $autoscaler) {
                    continue; // not ready yet: afterReady() or the next run applies it
                }

                if ($rows !== null) {
                    $desired = self::desired($rows, $live, $now, $timezone);
                    $patched += $this->converge($k8s, $namespace, $project, $live, $revision, $autoscaler, $desired, $desired) ? 1 : 0;

                    continue;
                }

                // The schedule was turned off: min_scale applies again, the warmer lets go
                $static = self::bounded($live, (int) (($live->deploy_config ?? [])['min_scale'] ?? $config->min_scale ?? 0));
                $patched += $this->converge($k8s, $namespace, $project, $live, $revision, $autoscaler, $static, null) ? 1 : 0;
            }
        }

        return $patched;
    }

    /**
     * Patch the PodAutoscaler when its min-scale differs from $desired, log what the deployment
     * runs with, and record $record (null: no warmer) on the project.
     *
     * @return bool whether it was patched
     */
    private function converge(KubernetesClient $k8s, string $namespace, Project $project, Deployment $live, string $revision, array $autoscaler, int $desired, ?int $record): bool
    {
        if (self::currentMinScale($autoscaler) === $desired) {
            $this->log($live, $desired);
            $this->record($project, $record);

            return false;
        }

        $ok = $k8s->mergePatch(self::PA_API, self::PA_KIND, $revision, $namespace, [
            'metadata' => ['annotations' => [self::ANNOTATION => (string) $desired]],
        ]);
        if (! $ok) {
            Log::warning('Timed warmer: the PodAutoscaler patch failed', ['project' => $project->slug, 'revision' => $revision, 'min_scale' => $desired]);

            return false;
        }

        Log::info('Timed warmer applied', ['project' => $project->slug, 'revision' => $revision, 'min_scale' => $desired]);
        $this->log($live, $desired);
        $this->record($project, $record, true);

        return true;
    }

    /**
     * warm_scale_applied / _at: the value (null: no warmer) and since when — on the project for
     * its own workload, on the environment (project_environments) for an environment's.
     */
    private function record(Project $project, ?int $value, bool $applied = false): void
    {
        $environment = EnvironmentScope::of($project);
        if ($environment !== null && ! SchemaColumns::has($environment, 'warm_scale_applied')) {
            return; // the organization's schema has not been migrated yet
        }
        $current = $environment !== null ? $environment->getAttribute('warm_scale_applied') : $project->getAttribute('warm_scale_applied');
        $same = $current === null ? $value === null : ($value !== null && (int) $current === $value);
        if ($same && ! $applied) {
            return;
        }

        $values = [
            'warm_scale_applied' => $value,
            'warm_scale_applied_at' => $value === null ? null : now(),
        ];
        if ($environment !== null) {
            ProjectEnvironment::query()->whereKey($environment->getKey())->update($values);
            $environment->forceFill($values)->syncOriginalAttributes(array_keys($values));

            return;
        }
        Project::query()->whereKey($project->getKey())->update($values);
        $project->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }

    /** deployments.sizing.warm_log: one more entry when the value changed (older entries pruned). */
    private function log(Deployment $live, int $value): void
    {
        if (! SchemaColumns::has($live, 'sizing')) {
            return;
        }

        $sizing = is_array($live->sizing) ? $live->sizing : [];
        $log = self::logOf($live) ?? [];
        $last = $log === [] ? null : $log[array_key_last($log)];
        if ($last !== null && $last[1] === $value) {
            return;
        }

        $now = now()->getTimestamp();
        $log[] = [$now, $value];

        // Older than LOG_KEEP_SECONDS: only the last of those stays, it starts the kept stretch
        $cutoff = $now - self::LOG_KEEP_SECONDS;
        while (count($log) > 1 && $log[1][0] <= $cutoff) {
            array_shift($log);
        }

        $sizing['warm_log'] = $log;
        Deployment::query()->whereKey($live->getKey())->update(['sizing' => json_encode($sizing)]);
        $live->setAttribute('sizing', $sizing);
        $live->syncOriginalAttribute('sizing');
    }

    /** At least one with scale to zero off, never above max_scale — as the deployment was applied. */
    private static function bounded(Deployment $deployment, int $value): int
    {
        $sizing = is_array($deployment->sizing) ? $deployment->sizing : [];
        $value = max(ScaleSchedule::floor($sizing['scale_to_zero'] ?? true), $value);
        $max = (int) ($sizing['max_scale'] ?? 0);

        return $max > 0 ? min($value, $max) : $value;
    }

    /** The deployment's min-scale before the warmer logged one: as it was applied. */
    private static function valueBeforeLog(Deployment $deployment): int
    {
        $sizing = is_array($deployment->sizing) ? $deployment->sizing : [];

        return max(ScaleSchedule::floor($sizing['scale_to_zero'] ?? true), (int) ($sizing['min_scale'] ?? 0));
    }

    private static function runsKnativeService(Deployment $deployment): bool
    {
        $sizing = is_array($deployment->sizing) ? $deployment->sizing : [];

        return ResourceReservation::runsKnativeService($sizing['trigger_type'] ?? 'http');
    }

    private static function reachable(?KnativeRegion $region): bool
    {
        return $region !== null
            && ! $region->is_demo
            && $region->is_active
            && $region->cluster_status === 'online'
            && (bool) $region->kubeconfig;
    }

    private static function namespace(KnativeRegion $region): string
    {
        return $region->project_namespace ?? $region->name;
    }
}
