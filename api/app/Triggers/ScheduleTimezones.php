<?php

namespace App\Triggers;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectTrigger;
use App\Services\Environments\EnvironmentProject;
use App\Services\GlobalProjects\GlobalProjectRegions;
use App\Services\KubernetesClient;
use App\Support\OrgTimezone;
use App\Triggers\Gateway\GatewayConfig;
use App\Triggers\Types\ScheduleTrigger;
use Illuminate\Support\Facades\Log;

/**
 * Schedules that follow the organization's time zone (a schedule trigger without config.timezone,
 * docs/triggers.md#schedules-and-time-zones) follow a change of that zone at once, as the timed
 * warmer does — not only with the project's next deploy.
 *
 * The deploy marks what follows the organization: the CronJob with the annotation
 * runr.dev/timezone-from: organization, a gateway.json schedule entry with
 * source.timezone_from: organization. apply() visits the deployed workloads of the organization's
 * projects with such a schedule — the project in each region it runs in, each environment's
 * workload — and changes only those marks: spec.timeZone of the CronJob (merge patch), the entry's
 * source.timezone in the gateway's config Secret together with the Deployment's config hash (the
 * pod restarts and reads the new file). Nothing else of the deployed trigger list changes: the rest
 * of a trigger change still waits for the next deploy.
 *
 * Run by ApplyScheduleTimezonesJob after PATCH /api/settings changed the zone. Best effort: a
 * region that does not answer is logged and skipped (its next deploy renders the zone).
 */
class ScheduleTimezones
{
    /** CronJob annotation: the schedule runs in the organization's zone. */
    public const ANNOTATION = 'runr.dev/timezone-from';

    /**
     * @return list<string> what changed ("<namespace>/<CronJob or gateway> → <zone>")
     */
    public function apply(?string $zone = null): array
    {
        $zone ??= OrgTimezone::get();
        $changed = [];

        foreach ($this->projects() as $project) {
            foreach ($this->workloads($project) as [$workload, $region]) {
                try {
                    $k8s = new KubernetesClient($region->kubeconfig);
                    $namespace = $region->project_namespace ?? $region->name;
                    array_push($changed, ...$this->applyCronJob($k8s, $namespace, $workload, $zone));
                    array_push($changed, ...$this->applyGateway($k8s, $namespace, $workload, $zone));
                } catch (\Throwable $e) {
                    Log::warning('Schedule time zone not applied', ['project' => $project->slug, 'workload' => $workload, 'region' => $region->name, 'error' => $e->getMessage()]);
                }
            }
        }

        if ($changed !== []) {
            Log::info('Schedules follow the organization\'s time zone', ['zone' => $zone, 'changed' => $changed]);
        }

        return $changed;
    }

    /**
     * Projects with an enabled schedule trigger that has no zone of its own.
     *
     * @return iterable<Project>
     */
    private function projects(): iterable
    {
        try {
            $ids = ProjectTrigger::query()->where('type', 'schedule')->where('enabled', true)->get()
                ->filter(fn (ProjectTrigger $t) => ScheduleTrigger::followsOrganization($t))
                ->pluck('project_id')->unique()->values()->all();
        } catch (\Throwable) {
            return []; // a schema without project_triggers yet
        }

        return $ids === [] ? [] : Project::query()->whereIn('id', $ids)->with('knativeRegion')->get();
    }

    /**
     * The workloads a project's deploys name: its slug in every region it runs in, and each
     * environment's workload in the environment's region.
     *
     * @return list<array{0: string, 1: KnativeRegion}>
     */
    private function workloads(Project $project): array
    {
        $out = [];
        foreach (GlobalProjectRegions::of($project) as $region) {
            $out[] = [$project->slug, $region];
        }

        try {
            if (ProjectEnvironment::tablesReady()) {
                foreach ($project->environments()->get() as $environment) {
                    if ($environment->is_primary) {
                        continue;
                    }
                    $view = EnvironmentProject::of($project, $environment);
                    if ($view->knativeRegion) {
                        $out[] = [$view->slug, $view->knativeRegion];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::debug('Schedule time zones: environments skipped', ['project' => $project->slug, 'error' => $e->getMessage()]);
        }

        return array_values(array_filter($out, fn ($w) => $w[1]->kubeconfig && ! $w[1]->is_demo));
    }

    /** @return list<string> */
    private function applyCronJob(KubernetesClient $k8s, string $namespace, string $workload, string $zone): array
    {
        $name = $workload.'-trigger';
        $cronJob = $k8s->getResourceStatus('batch/v1', 'CronJob', $name, $namespace);
        if (! $cronJob || ($cronJob['metadata']['annotations'][self::ANNOTATION] ?? null) !== ScheduleTrigger::FROM_ORGANIZATION
            || ($cronJob['spec']['timeZone'] ?? null) === $zone) {
            return [];
        }

        if (! $k8s->mergePatch('batch/v1', 'CronJob', $name, $namespace, ['spec' => ['timeZone' => $zone]])) {
            throw new \RuntimeException("patching the CronJob {$name} failed");
        }

        return ["{$namespace}/{$name} → {$zone}"];
    }

    /** @return list<string> */
    private function applyGateway(KubernetesClient $k8s, string $namespace, string $workload, string $zone): array
    {
        $name = $workload.'-qc';
        $secret = $k8s->getResourceStatus('v1', 'Secret', $name.'-config', $namespace);
        $raw = isset($secret['data']['gateway.json']) ? base64_decode((string) $secret['data']['gateway.json'], true) : false;
        // objects stay objects: an empty source must not come back as []
        $config = is_string($raw) ? json_decode($raw) : null;
        if (! $config instanceof \stdClass || ! is_array($config->triggers ?? null)) {
            return [];
        }

        $touched = false;
        foreach ($config->triggers as $entry) {
            $source = $entry->source ?? null;
            if (($entry->kind ?? null) === 'schedule' && $source instanceof \stdClass
                && ($source->timezone_from ?? null) === ScheduleTrigger::FROM_ORGANIZATION && ($source->timezone ?? null) !== $zone) {
                $source->timezone = $zone;
                $touched = true;
            }
        }
        if (! $touched) {
            return [];
        }

        $json = GatewayConfig::encode($config);
        if (! $k8s->mergePatch('v1', 'Secret', $name.'-config', $namespace, ['data' => ['gateway.json' => base64_encode($json)]])) {
            throw new \RuntimeException("updating the Secret {$name}-config failed");
        }
        // the gateway reads its config at start: the new hash rolls its pod
        $k8s->mergePatch('apps/v1', 'Deployment', $name, $namespace, ['spec' => ['template' => ['metadata' => ['annotations' => [GatewayConfig::HASH_ANNOTATION => GatewayConfig::hash($json)]]]]]);

        return ["{$namespace}/{$name} → {$zone}"];
    }
}
