<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Is a region the Kubernetes cluster RunR itself runs in?
 *
 * Compares the kube-system namespace UID seen through the region kubeconfig with
 * the one seen in-cluster through RunR's own service account (runr-api may get the
 * kube-system Namespace and nothing else cluster-wide, helm/templates/rbac.yaml).
 * inClusterClient() is the only place RunR's own token is used. Fail-safe: outside a pod, missing RBAC,
 * unreachable API or any other error → not the same cluster.
 * Results (also negative ones) are cached for a few minutes.
 */
class RunrClusterIdentity
{
    private const CACHE_TTL = 300;

    private const NONE = '-';

    /**
     * RunR's own namespace: config override, else the pod's service account namespace.
     */
    public function namespace(): ?string
    {
        $configured = config('knative.agent.runr_namespace');
        if ($configured) {
            return $configured;
        }

        $file = $this->serviceAccountDir().'/namespace';
        if (! is_readable($file)) {
            return null;
        }

        return trim((string) file_get_contents($file)) ?: null;
    }

    public function isRunrCluster(KnativeRegion $region): bool
    {
        if (! $region->kubeconfig) {
            return false;
        }

        $own = $this->ownClusterUid();
        if (! $own) {
            return false;
        }

        return hash_equals($own, $this->regionClusterUid($region) ?? '');
    }

    /**
     * Same check for a kubeconfig that has no region yet (e.g. while a region is being created).
     */
    public function isRunrClusterKubeconfig(string $kubeconfig): bool
    {
        $own = $this->ownClusterUid();
        if (! $own || $kubeconfig === '') {
            return false;
        }

        $uid = $this->remember(
            'runr:cluster-uid:kubeconfig:'.md5($kubeconfig),
            fn (): ?string => $this->kubeSystemUid(new KubernetesClient($kubeconfig)),
        );

        return hash_equals($own, $uid ?? '');
    }

    public function ownClusterUid(): ?string
    {
        return $this->remember('runr:cluster-uid:self', function (): ?string {
            $k8s = $this->inClusterClient();

            return $k8s ? $this->kubeSystemUid($k8s) : null;
        });
    }

    public function regionClusterUid(KnativeRegion $region): ?string
    {
        if (! $region->kubeconfig) {
            return null;
        }

        $key = 'runr:cluster-uid:region:'.$region->id.':'.md5($region->kubeconfig);

        return $this->remember($key, fn (): ?string => $this->kubeSystemUid(new KubernetesClient($region->kubeconfig)));
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function kubeSystemUid(KubernetesClient $k8s): ?string
    {
        $ns = $k8s->getClusterResource('v1', 'Namespace', 'kube-system');

        return $ns['metadata']['uid'] ?? null;
    }

    /**
     * KubernetesClient for the in-cluster API with RunR's service account token.
     * Public so callers that need to read RunR's own cluster (e.g. the region-level
     * function-domain TLS "reuse console certificate" mode) don't have
     * to re-implement in-cluster service-account discovery.
     */
    public function inClusterClient(): ?KubernetesClient
    {
        $dir = $this->serviceAccountDir();
        $tokenFile = $dir.'/token';
        if (! is_readable($tokenFile)) {
            return null;
        }

        $token = trim((string) file_get_contents($tokenFile));
        if ($token === '') {
            return null;
        }

        $cluster = ['server' => $this->inClusterServer()];
        $caFile = $dir.'/ca.crt';
        if (is_readable($caFile)) {
            $cluster['certificate-authority-data'] = base64_encode((string) file_get_contents($caFile));
        }

        return new KubernetesClient(Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'in-cluster',
            'clusters' => [['name' => 'in-cluster', 'cluster' => $cluster]],
            'users' => [['name' => 'runr', 'user' => ['token' => $token]]],
            'contexts' => [['name' => 'in-cluster', 'context' => ['cluster' => 'in-cluster', 'user' => 'runr']]],
        ], 10, 2));
    }

    private function inClusterServer(): string
    {
        $host = getenv('KUBERNETES_SERVICE_HOST');
        $port = getenv('KUBERNETES_SERVICE_PORT') ?: '443';

        if (! $host) {
            return 'https://kubernetes.default.svc';
        }

        return 'https://'.(str_contains($host, ':') ? "[{$host}]" : $host).':'.$port;
    }

    private function serviceAccountDir(): string
    {
        return rtrim((string) config('knative.agent.serviceaccount_dir', '/var/run/secrets/kubernetes.io/serviceaccount'), '/');
    }

    /**
     * Cache wrapper that never throws; null results are cached as NONE.
     */
    private function remember(string $key, callable $resolve): ?string
    {
        $safe = function () use ($key, $resolve): string {
            try {
                return $resolve() ?: self::NONE;
            } catch (Throwable $e) {
                Log::debug('RunR cluster identity lookup failed', ['key' => $key, 'error' => $e->getMessage()]);

                return self::NONE;
            }
        };

        try {
            $value = Cache::remember($key, self::CACHE_TTL, $safe);
        } catch (Throwable) {
            $value = $safe();
        }

        return $value === self::NONE ? null : $value;
    }
}
