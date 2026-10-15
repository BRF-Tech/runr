<?php

namespace App\Services\Bindings;

use App\Models\Project;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;

/**
 * Puts a project's bindings into its workloads:
 *
 *  1. the Secret <project>-bindings in the project namespace holds every env var of the
 *     attached bindings (deleted when nothing is attached);
 *  2. the running Knative Service gets envFrom on that Secret plus the template annotation
 *     runr.dev/bindings-hash — a changed hash is a new revision, i.e. a rolling restart onto the
 *     new values (attach, detach, rotation);
 *  3. the CronJob gets the same envFrom (each run reads the Secret anew).
 *
 * Not deployed yet → only the Secret; the next deploy adds envFrom (BindingEnv::apply).
 */
class ProjectBindingSync
{
    public function __construct(
        private BindingsCluster $cluster,
        private BindingDrivers $drivers,
    ) {}

    /** @return bool false when the region could not be updated (logged) */
    public function sync(Project $project): bool
    {
        $region = $project->knativeRegion;
        if (! $region?->kubeconfig || $region->is_demo) {
            return true;
        }

        $k8s = $this->cluster->client($region);
        $ns = BindingsCluster::projectNamespace($region);
        $secret = BindingEnv::secretName($project);
        $env = BindingEnv::projectEnv($project, $this->drivers);

        if ($env) {
            if (! $k8s->applyResource(BindingManifests::projectSecret($project, $ns, $env), $ns)) {
                throw new BindingException("Could not write Secret {$secret} in {$ns}.");
            }
        }

        $hash = $env ? BindingEnv::hash($env) : 'none';
        $ok = $this->patchKnativeService($k8s, $project, $ns, $secret, (bool) $env, $hash);
        $ok = $this->patchCronJob($k8s, $project, $ns, $secret, (bool) $env) && $ok;

        if (! $env) {
            $k8s->deleteResource('v1', 'Secret', $secret, $ns);
        }

        return $ok;
    }

    /** Project gone: its Secret too. */
    public function forget(Project $project): void
    {
        $region = $project->knativeRegion;
        if (! $region?->kubeconfig) {
            return;
        }

        $this->cluster->client($region)->deleteResource('v1', 'Secret', BindingEnv::secretName($project), BindingsCluster::projectNamespace($region));
    }

    private function patchKnativeService(KubernetesClient $k8s, Project $project, string $ns, string $secret, bool $present, string $hash): bool
    {
        // Same name as KnativeDeployService::serviceName()
        $service = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $project->slug, $ns);
        $container = $service['spec']['template']['spec']['containers'][0] ?? null;
        if (! is_array($container)) {
            return true;
        }

        $ops = $this->envFromOps('/spec/template/spec/containers/0', $container, $secret, $present);
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

    private function patchCronJob(KubernetesClient $k8s, Project $project, string $ns, string $secret, bool $present): bool
    {
        // Same name as KnativeDeployService::cronJobName()
        $name = $project->slug.'-trigger';
        $cron = $k8s->getResourceStatus('batch/v1', 'CronJob', $name, $ns);
        $container = $cron['spec']['jobTemplate']['spec']['template']['spec']['containers'][0] ?? null;
        if (! is_array($container)) {
            return true;
        }

        $ops = $this->envFromOps('/spec/jobTemplate/spec/template/spec/containers/0', $container, $secret, $present);
        if (! $ops) {
            return true;
        }

        $ok = $k8s->jsonPatch('batch/v1', 'CronJob', $name, $ns, $ops);
        if (! $ok) {
            Log::warning('Bindings: CronJob env update failed', ['project' => $project->slug]);
        }

        return $ok;
    }

    /** JSON patch operations that make the container's envFrom match. */
    private function envFromOps(string $pointer, array $container, string $secret, bool $present): array
    {
        $before = $container['envFrom'] ?? [];
        $after = BindingEnv::withEnvFrom($container, $secret, $present)['envFrom'] ?? [];

        if ($before == $after) {
            return [];
        }

        return $after
            ? [['op' => 'add', 'path' => $pointer.'/envFrom', 'value' => $after]]
            : [['op' => 'remove', 'path' => $pointer.'/envFrom']];
    }
}
