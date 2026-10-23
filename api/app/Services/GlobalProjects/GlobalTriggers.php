<?php

namespace App\Services\GlobalProjects;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Services\GlobalDns\GlobalRegionSetup;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where the triggers of a global project run (docs/global-projects.md, "Triggers").
 *
 * Every region runs the project's Knative Service (HTTP and workflow triggers answer in every
 * region). Everything else — the CronJob of a schedule (<slug>-trigger) and the event gateway or
 * the workflow queue bridge (Deployment <slug>-qc: queue, topic, webhook, platform events, broker
 * and external sources, schedules next to other triggers) — runs in one region only: the
 * project's primary, the **trigger region**. Each event is delivered once, not once per region.
 *
 * The other regions hold the same objects on standby — the CronJob suspended, the Deployment at
 * zero replicas — so a switchover or a failover of the primary only flips them: activate() in the
 * new primary, standby() in the region it leaves (as soon as that region's cluster answers).
 */
final class GlobalTriggers
{
    /** Deployment of the event gateway / workflow queue bridge. */
    public static function gatewayName(Project $project): string
    {
        return $project->slug.'-qc';
    }

    /** The CronJob of a project whose only trigger is a schedule. */
    public static function cronJobName(Project $project): string
    {
        return $project->slug.'-trigger';
    }

    /**
     * A deploy's manifests for a region that is not the trigger region: the trigger workloads on
     * standby (CronJob suspended, gateway at zero replicas), everything else as it is.
     *
     * @param  list<array>  $manifests
     * @return list<array>
     */
    public static function standbyManifests(array $manifests, Project $project): array
    {
        foreach ($manifests as $i => $manifest) {
            $kind = $manifest['kind'] ?? '';
            $name = $manifest['metadata']['name'] ?? '';
            if ($kind === 'CronJob' && $name === self::cronJobName($project)) {
                $manifests[$i]['spec']['suspend'] = true;
            } elseif ($kind === 'Deployment' && $name === self::gatewayName($project)) {
                $manifests[$i]['spec']['replicas'] = 0;
            }
        }

        return $manifests;
    }

    /** Whether a deploy's manifests run trigger workloads (a CronJob or the gateway). */
    public static function hasTriggerWorkloads(array $manifests, Project $project): bool
    {
        foreach ($manifests as $manifest) {
            $name = $manifest['metadata']['name'] ?? '';
            if (($manifest['kind'] ?? '') === 'CronJob' && $name === self::cronJobName($project)) {
                return true;
            }
            if (($manifest['kind'] ?? '') === 'Deployment' && $name === self::gatewayName($project)) {
                return true;
            }
        }

        return false;
    }

    /** The trigger workloads of the region run (the region became the trigger region). */
    public function activate(Project $project, KnativeRegion $region, ?KubernetesClient $k8s = null): bool
    {
        return $this->set($project, $region, true, $k8s);
    }

    /** The trigger workloads of the region stop (the region is no longer the trigger region). */
    public function standby(Project $project, KnativeRegion $region, ?KubernetesClient $k8s = null): bool
    {
        return $this->set($project, $region, false, $k8s);
    }

    /**
     * Make the primary the only region whose trigger workloads run: activate the primary, every
     * other reachable region on standby. Returns the regions that could not be reached (their
     * rows keep triggers_active until a later call reaches them).
     *
     * @return list<string> region names not reached
     */
    public function place(Project $project): array
    {
        $missed = [];
        foreach (ProjectRegion::query()->where('project_id', $project->getKey())->with('knativeRegion')->get() as $row) {
            $region = $row->knativeRegion;
            if (! $region) {
                continue;
            }
            $primary = (string) $row->knative_region_id === (string) $project->knative_region_id;
            if ($row->status === ProjectRegion::STATUS_REMOVING) {
                continue;
            }
            if ($primary === (bool) $row->getAttribute('triggers_active') && ! $primary) {
                continue;
            }
            $ok = $primary ? $this->activate($project, $region) : $this->standby($project, $region);
            if ($ok) {
                $row->forceFill(['triggers_active' => $primary])->save();
            } else {
                $missed[] = (string) $region->name;
            }
        }

        return $missed;
    }

    private function set(Project $project, KnativeRegion $region, bool $on, ?KubernetesClient $k8s): bool
    {
        if ($region->is_demo || ! $region->kubeconfig) {
            return true;
        }

        try {
            $k8s ??= new KubernetesClient((string) $region->kubeconfig);
            $namespace = GlobalRegionSetup::projectNamespace($region);
            $ok = true;

            if ($k8s->getResourceStatus('batch/v1', 'CronJob', self::cronJobName($project), $namespace) !== null) {
                $ok = $k8s->mergePatch('batch/v1', 'CronJob', self::cronJobName($project), $namespace, ['spec' => ['suspend' => ! $on]]);
            }
            if ($k8s->getResourceStatus('apps/v1', 'Deployment', self::gatewayName($project), $namespace) !== null) {
                $ok = $k8s->mergePatch('apps/v1', 'Deployment', self::gatewayName($project), $namespace, ['spec' => ['replicas' => $on ? 1 : 0]]) && $ok;
            }

            return $ok;
        } catch (Throwable $e) {
            Log::warning('Global project triggers not switched', ['project' => $project->slug, 'region' => $region->name, 'on' => $on, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
