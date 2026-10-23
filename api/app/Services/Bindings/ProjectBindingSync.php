<?php

namespace App\Services\Bindings;

use App\Models\Project;
use App\Models\ProjectRegion;
use App\Services\Environments\EnvironmentScope;
use App\Services\GlobalProjects\GlobalProjectRegions;
use App\Services\KubernetesClient;
use App\Services\Secrets\External\ExternalSecretManifests;
use Illuminate\Support\Facades\Log;

/**
 * Puts a project's bindings into its workloads:
 *
 *  1. the Secret <project>-bindings in the project namespace holds every env var of the
 *     attached bindings (deleted when nothing is attached);
 *  2. the running Knative Service gets envFrom on that Secret plus the template annotation
 *     runr.dev/bindings-hash — a changed hash is a new revision, i.e. a rolling restart onto the
 *     new values (attach, detach, rotation);
 *  3. the CronJob gets the same envFrom (each run reads the Secret anew);
 *  4. dynamic Postgres credentials (DynamicDbCredentials): per dynamic attachment a generator and
 *     an ExternalSecret that fill <project>-pgdyn-<…> from the region's OpenBao, referenced after
 *     the bindings Secret; the ones of attachments that are no longer dynamic go.
 *
 * Not deployed yet → only the Secrets; the next deploy adds envFrom (BindingEnv::apply).
 */
class ProjectBindingSync
{
    public function __construct(
        private BindingsCluster $cluster,
        private BindingDrivers $drivers,
    ) {}

    /**
     * A global project (docs/global-projects.md, "Data"): every region's copy gets the env of its
     * region — the data link's host where the binding lives elsewhere, a dedicated binding's
     * replica of the region for reads.
     *
     * @return bool false when a region could not be updated (logged)
     */
    public function sync(Project $project): bool
    {
        $ok = $this->syncRegion($project);
        // an environment's view runs in its own region only
        if (! $project->isGlobal() || EnvironmentScope::idOf($project) !== null) {
            return $ok;
        }

        foreach (GlobalProjectRegions::secondaries($project) as $row) {
            $region = $row->knativeRegion;
            if (! $region || in_array($row->status, [ProjectRegion::STATUS_REMOVING, ProjectRegion::STATUS_PENDING], true)) {
                continue;
            }
            $regional = (clone $project)->setRelation('knativeRegion', $region);
            $regional->setAttribute('knative_region_id', $region->getKey());
            try {
                $ok = $this->syncRegion($regional) && $ok;
            } catch (\Throwable $e) {
                Log::warning('Bindings: a global project region not synced', ['project' => $project->slug, 'region' => $region->name, 'error' => $e->getMessage()]);
                $ok = false;
            }
        }

        return $ok;
    }

    /** One region of the project: $project is its view there (its knativeRegion). */
    private function syncRegion(Project $project): bool
    {
        $region = $project->knativeRegion;
        if (! $region?->kubeconfig || $region->is_demo) {
            return true;
        }

        $k8s = $this->cluster->client($region);
        $ns = BindingsCluster::projectNamespace($region);
        $secret = BindingEnv::secretName($project);
        $env = BindingEnv::projectEnv($project, $this->drivers);
        $dynamic = DynamicDbCredentials::secretNames($project);

        if ($env) {
            if (! $k8s->applyResource(BindingManifests::projectSecret($project, $ns, $env), $ns)) {
                throw new BindingException("Could not write Secret {$secret} in {$ns}.");
            }
        }
        foreach (DynamicDbCredentials::manifests($project, $ns) as $manifest) {
            if (! $k8s->applyResource($manifest, $ns)) {
                throw new BindingException("Could not write {$manifest['kind']} {$manifest['metadata']['name']} in {$ns}.");
            }
        }
        $this->dropStaleDynamic($k8s, $project, $ns, $dynamic);

        // The dynamic Secrets' names count: switching an attachment is a new revision
        $hash = $env || $dynamic ? BindingEnv::hash($env + array_fill_keys($dynamic, 'dynamic')) : 'none';
        $ok = $this->patchKnativeService($k8s, $project, $ns, $secret, (bool) $env, $hash, $dynamic);
        $ok = $this->patchCronJob($k8s, $project, $ns, $secret, (bool) $env, $dynamic) && $ok;

        if (! $env) {
            $k8s->deleteResource('v1', 'Secret', $secret, $ns);
        }

        return $ok;
    }

    /** Project gone: its Secret too. */
    public function forget(Project $project): void
    {
        // a global project's Secret is in every region of it
        foreach (GlobalProjectRegions::of($project) as $region) {
            if (! $region->kubeconfig || $region->is_demo) {
                continue;
            }

            $k8s = $this->cluster->client($region);
            $ns = BindingsCluster::projectNamespace($region);
            $k8s->deleteResource('v1', 'Secret', BindingEnv::secretName($project), $ns);
            $this->dropStaleDynamic($k8s, $project, $ns, []);
        }
    }

    /**
     * The generators and ExternalSecrets of dynamic credentials the project no longer has (their
     * Secrets go with the ExternalSecrets). Nothing listed when the operator is not installed.
     *
     * @param  list<string>  $keep  names of the ExternalSecrets to keep
     */
    private function dropStaleDynamic(KubernetesClient $k8s, Project $project, string $ns, array $keep): void
    {
        $labels = [ExternalSecretManifests::LABEL => 'pgdyn', 'runr/project-id' => (string) $project->id];
        try {
            foreach ($k8s->listResourcesOrFail(ExternalSecretManifests::API_VERSION, 'ExternalSecret', $ns, $labels) as $item) {
                $name = (string) ($item['metadata']['name'] ?? '');
                if ($name !== '' && ! in_array($name, $keep, true)) {
                    $k8s->deleteResource(ExternalSecretManifests::API_VERSION, 'ExternalSecret', $name, $ns);
                }
            }
            $generators = DynamicDbCredentials::attachmentsOf($project)->map(fn ($a) => DynamicDbCredentials::generatorName($a))->all();
            foreach ($k8s->listResourcesOrFail(DynamicDbCredentials::GENERATOR_API, 'VaultDynamicSecret', $ns, $labels) as $item) {
                $name = (string) ($item['metadata']['name'] ?? '');
                if ($name !== '' && ($keep === [] || ! in_array($name, $generators, true))) {
                    $k8s->deleteResource(DynamicDbCredentials::GENERATOR_API, 'VaultDynamicSecret', $name, $ns);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Bindings: dynamic credential objects not cleaned up', ['project' => $project->slug, 'error' => $e->getMessage()]);
        }
    }

    /** @param list<string> $dynamic */
    private function patchKnativeService(KubernetesClient $k8s, Project $project, string $ns, string $secret, bool $present, string $hash, array $dynamic = []): bool
    {
        // Same name as KnativeDeployService::serviceName()
        $service = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $project->slug, $ns);
        $container = $service['spec']['template']['spec']['containers'][0] ?? null;
        if (! is_array($container)) {
            return true;
        }

        $ops = $this->envFromOps('/spec/template/spec/containers/0', $container, $project, $secret, $present, $dynamic);
        $current = $service['spec']['template']['metadata']['annotations'][BindingEnv::HASH_ANNOTATION] ?? null;

        if ($current !== $hash && ($present || $current !== null)) {
            if (! isset($service['spec']['template']['metadata'])) {
                $ops[] = ['op' => 'add', 'path' => '/spec/template/metadata', 'value' => ['annotations' => [BindingEnv::HASH_ANNOTATION => $hash]]];
            } elseif (! isset($service['spec']['template']['metadata']['annotations'])) {
                $ops[] = ['op' => 'add', 'path' => '/spec/template/metadata/annotations', 'value' => [BindingEnv::HASH_ANNOTATION => $hash]];
            } else {
                $ops[] = ['op' => 'add', 'path' => '/spec/template/metadata/annotations/'.str_replace('/', '~1', BindingEnv::HASH_ANNOTATION), 'value' => $hash];
            }
        }

        if (! $ops) {
            return true;
        }

        $ok = $k8s->jsonPatch('serving.knative.dev/v1', 'Service', $project->slug, $ns, $ops);
        if (! $ok) {
            Log::warning('Bindings: Knative Service env update failed', ['project' => $project->slug]);
        }

        return $ok;
    }

    /** @param list<string> $dynamic */
    private function patchCronJob(KubernetesClient $k8s, Project $project, string $ns, string $secret, bool $present, array $dynamic = []): bool
    {
        // Same name as KnativeDeployService::cronJobName()
        $name = $project->slug.'-trigger';
        $cron = $k8s->getResourceStatus('batch/v1', 'CronJob', $name, $ns);
        $container = $cron['spec']['jobTemplate']['spec']['template']['spec']['containers'][0] ?? null;
        if (! is_array($container)) {
            return true;
        }

        $ops = $this->envFromOps('/spec/jobTemplate/spec/template/spec/containers/0', $container, $project, $secret, $present, $dynamic);
        if (! $ops) {
            return true;
        }

        $ok = $k8s->jsonPatch('batch/v1', 'CronJob', $name, $ns, $ops);
        if (! $ok) {
            Log::warning('Bindings: CronJob env update failed', ['project' => $project->slug]);
        }

        return $ok;
    }

    /**
     * JSON patch operations that make the container's envFrom match: the bindings Secret, then
     * the Secrets of the dynamic credentials.
     *
     * @param  list<string>  $dynamic
     */
    private function envFromOps(string $pointer, array $container, Project $project, string $secret, bool $present, array $dynamic = []): array
    {
        $before = $container['envFrom'] ?? [];
        $after = BindingEnv::withDynamicEnvFrom(BindingEnv::withEnvFrom($container, $secret, $present), $project, $dynamic)['envFrom'] ?? [];

        if ($before == $after) {
            return [];
        }

        return $after
            ? [['op' => 'add', 'path' => $pointer.'/envFrom', 'value' => $after]]
            : [['op' => 'remove', 'path' => $pointer.'/envFrom']];
    }
}
