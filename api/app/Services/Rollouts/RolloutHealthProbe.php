<?php

namespace App\Services\Rollouts;

use App\Models\DeploymentRollout;
use App\Models\Project;
use App\Services\KubernetesClient;

/**
 * One health check of a blue-green rollout's new revision before it promotes itself: GET of the
 * rollout's health path on that revision, 2xx passes. The request goes through the region's
 * Kubernetes API service proxy to the Knative activator, addressed to the revision by the
 * Knative-Serving-Revision header (the model playground's way, ModelServiceDeployer::invoke): no
 * DNS or ingress route is needed from RunR to the function, and a revision scaled to zero is
 * started by the activator.
 */
class RolloutHealthProbe
{
    /**
     * @return array{ok: bool, status: int|null, error: string|null}
     */
    public function check(Project $project, DeploymentRollout $rollout, string $path): array
    {
        $region = $project->knativeRegion;
        if (! $region || ! $region->kubeconfig || ! $rollout->revision) {
            return ['ok' => false, 'status' => null, 'error' => 'The region has no cluster to reach the revision'];
        }

        $namespace = RolloutTraffic::namespace($region);

        try {
            $response = (new KubernetesClient($region->kubeconfig))->serviceProxySend(
                'GET',
                $region->knative_serving_namespace ?? 'knative-serving',
                'activator-service',
                80,
                ltrim($path, '/'),
                [
                    'Host' => $project->slug.'.'.$namespace.'.svc.cluster.local',
                    'Knative-Serving-Namespace' => $namespace,
                    'Knative-Serving-Revision' => $rollout->revision,
                    'Accept' => '*/*',
                    'User-Agent' => 'runr-rollout-health',
                ],
                null,
                max(1, (int) config('knative.rollouts.health_timeout_seconds', 10)),
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }

        $status = $response->status();
        $ok = $status >= 200 && $status < 300;

        return ['ok' => $ok, 'status' => $status, 'error' => $ok ? null : mb_substr(trim((string) $response->body()), 0, 200)];
    }
}
