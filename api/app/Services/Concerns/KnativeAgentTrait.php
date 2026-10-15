<?php

namespace App\Services\Concerns;

use App\Models\KnativeRegion;
use App\Services\Helpers\AgentToken;
use App\Services\Helpers\AgentWsUrlResolver;
use App\Services\KubernetesClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Deploys the runr-agent (Go) as a Kubernetes Deployment.
 * The agent collects metrics, tracks sessions, and watches events.
 * Pattern follows KnativeCAdvisorTrait.
 */
trait KnativeAgentTrait
{
    /**
     * Deploy the runr-agent into the observability namespace.
     * The per-region token is written to the Secret first; only then its hash is
     * stored on the region (AgentController accepts nothing else).
     */
    private function deployAgent(KubernetesClient $k8s, KnativeRegion $region, string $ns = 'observability'): void
    {
        $clusterKinds = ['ClusterRole', 'ClusterRoleBinding'];
        $token = $this->resolveAgentToken($k8s, $region, $ns);
        $tokenHash = AgentToken::hash($token);

        foreach ($this->agentManifests($region, $ns, $token) as $manifest) {
            $applied = in_array($manifest['kind'], $clusterKinds)
                ? $k8s->applyClusterResource($manifest)
                : $k8s->applyResource($manifest, $ns);

            if ($manifest['kind'] !== 'Secret') {
                continue;
            }

            if (! $applied) {
                throw new RuntimeException("runr-agent token Secret could not be applied in namespace {$ns}");
            }

            if (! hash_equals((string) $region->agent_token_hash, $tokenHash)) {
                try {
                    $region->forceFill(['agent_token_hash' => $tokenHash])->save();
                } catch (QueryException $e) {
                    // Tenant schema created before 0.3.3 without the column (php artisan tenancy:migrate)
                    Log::warning('runr-agent token hash not stored', ['region' => $region->id, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    /**
     * Keep the token already in the cluster when it still matches the stored hash
     * (no needless rotation / agent restart); otherwise mint a new one.
     */
    private function resolveAgentToken(KubernetesClient $k8s, KnativeRegion $region, string $ns): string
    {
        if ($region->agent_token_hash) {
            try {
                $secret = $k8s->getResourceStatus('v1', 'Secret', 'runr-agent-token', $ns);
                $current = base64_decode($secret['data']['token'] ?? '', true);

                if ($current && AgentToken::verify($region->agent_token_hash, $current)) {
                    return $current;
                }
            } catch (Throwable) {
                // Secret unreadable → rotate
            }
        }

        return AgentToken::generate();
    }

    /**
     * Remove the runr-agent from the cluster.
     */
    private function removeAgent(KubernetesClient $k8s, string $ns = 'observability'): void
    {
        $k8s->deleteResource('apps/v1', 'Deployment', 'runr-agent', $ns);
        $k8s->deleteResource('v1', 'ServiceAccount', 'runr-agent', $ns);
        $k8s->deleteClusterResource('rbac.authorization.k8s.io/v1', 'ClusterRole', 'runr-agent');
        $k8s->deleteClusterResource('rbac.authorization.k8s.io/v1', 'ClusterRoleBinding', 'runr-agent');
        $k8s->deleteResource('v1', 'Secret', 'runr-agent-token', $ns);
    }

    private function agentManifests(KnativeRegion $region, string $ns, string $token): array
    {
        $projectNs = $region->project_namespace ?? 'runr';
        $wsUrl = $this->resolveAgentWsUrl($region);

        return [
            $this->agentServiceAccount($ns),
            $this->agentClusterRole(),
            $this->agentClusterRoleBinding($ns),
            $this->agentSecret($ns, $token),
            $this->agentDeployment($ns, $region->id, $projectNs, $wsUrl, $region, AgentToken::revision(AgentToken::hash($token))),
        ];
    }

    private function agentServiceAccount(string $ns): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ServiceAccount',
            'metadata' => ['name' => 'runr-agent', 'namespace' => $ns],
        ];
    }

    private function agentClusterRole(): array
    {
        return [
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'ClusterRole',
            'metadata' => ['name' => 'runr-agent'],
            'rules' => [
                [
                    'apiGroups' => [''],
                    'resources' => ['pods', 'events', 'nodes'],
                    'verbs' => ['get', 'list', 'watch'],
                ],
                [
                    'apiGroups' => ['apps'],
                    'resources' => ['deployments', 'replicasets'],
                    'verbs' => ['get', 'list', 'watch'],
                ],
            ],
        ];
    }

    private function agentClusterRoleBinding(string $ns): array
    {
        return [
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'ClusterRoleBinding',
            'metadata' => ['name' => 'runr-agent'],
            'roleRef' => [
                'apiGroup' => 'rbac.authorization.k8s.io',
                'kind' => 'ClusterRole',
                'name' => 'runr-agent',
            ],
            'subjects' => [[
                'kind' => 'ServiceAccount',
                'name' => 'runr-agent',
                'namespace' => $ns,
            ]],
        ];
    }

    private function agentSecret(string $ns, string $token): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => 'runr-agent-token', 'namespace' => $ns],
            'type' => 'Opaque',
            'stringData' => ['token' => $token],
        ];
    }

    private function agentDeployment(string $ns, string $regionId, string $projectNs, string $wsUrl, KnativeRegion $region, string $tokenRevision): array
    {
        $labels = ['app.kubernetes.io/name' => 'runr-agent', 'app.kubernetes.io/managed-by' => 'runr'];
        $promUrl = $region->prometheus_url ?? 'http://prometheus-operated.observability.svc.cluster.local:9090';

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => 'runr-agent', 'namespace' => $ns, 'labels' => $labels],
            'spec' => [
                'replicas' => 1,
                'selector' => ['matchLabels' => $labels],
                'template' => [
                    // Token env comes from secretKeyRef (read at pod start) → roll on token change
                    'metadata' => ['labels' => $labels, 'annotations' => ['runr.dev/agent-token-rev' => $tokenRevision]],
                    'spec' => [
                        'serviceAccountName' => 'runr-agent',
                        'containers' => [[
                            'name' => 'agent',
                            'image' => 'ghcr.io/runrsh/runr-agent:latest',
                            'env' => [
                                ['name' => 'RUNR_WS_URL',      'value' => $wsUrl],
                                ['name' => 'RUNR_REGION_ID',   'value' => $regionId],
                                ['name' => 'PROMETHEUS_URL',   'value' => $promUrl],
                                ['name' => 'PROJECT_NAMESPACE', 'value' => $projectNs],
                                [
                                    'name' => 'RUNR_AGENT_TOKEN',
                                    'valueFrom' => ['secretKeyRef' => ['name' => 'runr-agent-token', 'key' => 'token']],
                                ],
                            ],
                            'resources' => [
                                'requests' => ['cpu' => '100m', 'memory' => '128Mi'],
                                'limits' => ['cpu' => '200m', 'memory' => '256Mi'],
                            ],
                        ]],
                    ],
                ],
            ],
        ];
    }

    /**
     * Region override → in-cluster gateway (same cluster as RunR) → RUNR_AGENT_WS_URL → APP_URL.
     *
     * @see AgentWsUrlResolver
     */
    private function resolveAgentWsUrl(KnativeRegion $region): string
    {
        return app(AgentWsUrlResolver::class)->resolve($region);
    }
}
