<?php

namespace App\Services\Secrets;

use App\Models\ProjectSecret;
use App\Models\RegionSecretSetting;
use App\Services\KubernetesClient;
use App\Services\Secrets\External\ExternalSecretManifests;
use Illuminate\Support\Collection;

/**
 * Secret variables that are references to a secret store of the organization (source "external",
 * docs/secret-stores.md): RunR never sees their values. A deploy applies the stores' SecretStores
 * and service accounts and the project's ExternalSecret <slug>-ext-env; External Secrets Operator
 * in the region reads the values with the region's identity and fills the Secret <slug>-ext-env,
 * which the workload reads through secretKeyRef entries. The status sync
 * (ExternalSecretStatusSync) reports per variable whether it synced.
 */
class ExternalSecretBackend implements SecretBackend
{
    public function source(): string
    {
        return ProjectSecret::SOURCE_EXTERNAL;
    }

    public function holdsValues(): bool
    {
        return false;
    }

    public function write(SecretScope $scope, array $values): void
    {
        throw new SecretBackendException(SecretBackendException::UNSUPPORTED, __('api.secret.reference_has_no_value'));
    }

    public function remove(SecretScope $scope, array $names): void
    {
        // The ExternalSecret follows the references with the next deploy
    }

    public function read(SecretScope $scope, ProjectSecret $secret): string
    {
        throw new SecretBackendException(SecretBackendException::UNSUPPORTED, __('api.secret.reveal_external', [
            'store' => $secret->store->name ?? '—',
        ]));
    }

    public function readValues(SecretScope $scope, array $names): array
    {
        return [];
    }

    public function purge(SecretScope $scope): void
    {
        // RunR keeps nothing of a reference outside the region's objects
    }

    public function envEntry(SecretScope $scope, ProjectSecret $secret): array
    {
        return [
            'name' => $secret->name,
            'valueFrom' => ['secretKeyRef' => [
                'name' => $scope->externalName,
                'key' => $secret->name,
            ]],
        ];
    }

    public function manifests(SecretScope $scope, Collection $secrets): array
    {
        $secrets = $secrets->filter(fn (ProjectSecret $s) => $s->store !== null)->values();
        if ($secrets->isEmpty()) {
            return [];
        }

        $manifests = [];
        foreach ($secrets->pluck('store')->unique('id') as $store) {
            $manifests[] = ExternalSecretManifests::serviceAccount($store, $scope->namespace);
            $manifests[] = ExternalSecretManifests::secretStore($store, $scope->namespace);
        }
        $manifests[] = ExternalSecretManifests::externalSecret($scope, $secrets);

        return $manifests;
    }

    /**
     * References need External Secrets Operator in the region; without any, a previous deploy's
     * ExternalSecret goes (its Secret with it).
     */
    public function prepareDeploy(SecretScope $scope, Collection $secrets, array $runrValues): void
    {
        if ($secrets->isEmpty()) {
            if ($scope->hasCluster() && RegionSecretSetting::forRegion($scope->region)->eso_status !== RegionSecretSetting::ESO_NOT_INSTALLED) {
                try {
                    (new KubernetesClient($scope->kubeconfig()))->deleteResource(ExternalSecretManifests::API_VERSION, 'ExternalSecret', $scope->externalName, $scope->namespace);
                } catch (\Throwable) {
                    // best effort: the CRD may be gone with the operator
                }
            }

            return;
        }

        if (! $scope->hasCluster()) {
            throw SecretBackendException::noCluster($scope->region?->name);
        }
        if (! RegionSecretSetting::forRegion($scope->region)->esoReady()) {
            throw new SecretBackendException(SecretBackendException::NOT_INSTALLED, __('api.secret.eso_not_installed', ['region' => $scope->region?->name]));
        }
        foreach ($secrets as $secret) {
            if ($secret->store === null) {
                throw new SecretBackendException(SecretBackendException::UNSUPPORTED, __('api.secret.store_missing', ['name' => $secret->name]));
            }
        }

        // Cluster-scoped, so not among the namespaced manifests: the TokenReview right of the
        // service accounts of Vault / OpenBao stores with Kubernetes auth
        $k8s = null;
        foreach ($secrets->pluck('store')->unique('id') as $store) {
            $binding = ExternalSecretManifests::tokenReviewBinding($store, $scope->namespace);
            if ($binding === null) {
                continue;
            }
            $k8s ??= new KubernetesClient($scope->kubeconfig());
            if (! $k8s->applyClusterResource($binding)) {
                throw SecretBackendException::unreachable($scope->region?->name, new \RuntimeException("applying ClusterRoleBinding {$binding['metadata']['name']} failed"));
            }
        }
    }

    public function teardownTargets(SecretScope $scope, bool $withValues): array
    {
        // The Secret it filled is owned by it and goes with it
        return [[ExternalSecretManifests::API_VERSION, 'ExternalSecret', $scope->externalName]];
    }
}
