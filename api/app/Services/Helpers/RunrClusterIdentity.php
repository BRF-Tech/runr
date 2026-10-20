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

        $namespace = $this->serviceAccountFile('namespace');

        return $namespace === null ? null : trim($namespace);
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
        $token = $this->serviceAccountFile('token');
        if ($token === null) {
            return null;
        }
        $token = trim($token);

        $cluster = ['server' => $this->inClusterServer()];
        $ca = $this->serviceAccountFile('ca.crt');
        if ($ca !== null) {
            $cluster['certificate-authority-data'] = base64_encode($ca);
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
     * Content of a file of the pod's service account volume; null when it is missing or blank.
     *
     * Kubernetes renews that volume with every token rotation (about every 48 minutes for the
     * default one-hour token): it points the volume's `..data` link at a new directory and deletes
     * the old one. PHP's realpath cache (per worker, realpath_cache_ttl: 120 s) may still resolve the
     * file into the deleted directory, where it reads as missing, so a failed read of a linked file
     * is retried once with that cache cleared.
     */
    private function serviceAccountFile(string $name): ?string
    {
        $file = $this->serviceAccountDir().'/'.$name;

        $content = self::readFile($file);
        if ($content === null && is_link($file)) {
            clearstatcache(true);
            $content = self::readFile($file);
        }

        return $content;
    }

    private static function readFile(string $file): ?string
    {
        $content = is_readable($file) ? @file_get_contents($file) : false;

        return is_string($content) && trim($content) !== '' ? $content : null;
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
