<?php

namespace App\Services\Secrets;

use App\Models\ProjectSecret;
use App\Models\RegionSecretSetting;
use App\Services\Bindings\DynamicDbCredentials;
use App\Services\KubernetesClient;
use App\Services\Secrets\External\ExternalSecretManifests;
use App\Services\Secrets\OpenBao\OpenBaoAccess;
use App\Services\Secrets\OpenBao\OpenBaoClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Secret variables whose value lives in the region's own OpenBao (source "openbao",
 * docs/secret-stores.md "OpenBao in the region"): RunR writes the value into the KV v2 secret
 * runr/data/projects/<project id> (a merge patch, key by key) with its own short-lived login, and
 * keeps the metadata. The workload reads it through External Secrets Operator: the project's
 * SecretStore logs in as the project's service account runr-bao-<project id>, whose OpenBao role
 * may read that one secret, and its ExternalSecret <slug>-bao-env fills the Secret of that name.
 *
 * Over a Kubernetes Secret this adds encryption at rest inside OpenBao, versions of every value,
 * OpenBao's audit log and a per-project identity; new values of a region go here while its OpenBao
 * runs (region_secret_settings.openbao_default).
 */
class OpenBaoSecretBackend implements SecretBackend
{
    public function source(): string
    {
        return ProjectSecret::SOURCE_OPENBAO;
    }

    public function holdsValues(): bool
    {
        return true;
    }

    public function write(SecretScope $scope, array $values): void
    {
        $client = $this->client($scope);
        $this->call($scope, fn () => $client->kvPatch(OpenBaoAccess::projectPath($scope->project), $values));
        $this->forceSync($scope);
    }

    public function remove(SecretScope $scope, array $names): void
    {
        if ($names === [] || ! $this->ready($scope)) {
            return;
        }
        $client = $this->client($scope);
        $this->call($scope, fn () => $client->kvPatch(OpenBaoAccess::projectPath($scope->project), array_fill_keys($names, null)));
    }

    public function read(SecretScope $scope, ProjectSecret $secret): string
    {
        // A copy RunR 0.5.10 changed after a rollback: the database holds the newer value
        if ($secret->isHeldInDatabase()) {
            return (string) $secret->value;
        }

        $values = $this->readValues($scope, [$secret->name]);
        if (! array_key_exists($secret->name, $values)) {
            throw SecretBackendException::missing($scope->region?->name, [$secret->name]);
        }

        return $values[$secret->name];
    }

    public function readValues(SecretScope $scope, array $names): array
    {
        if ($names === []) {
            return [];
        }
        $client = $this->client($scope);
        $values = $this->call($scope, fn () => $client->kvRead(OpenBaoAccess::projectPath($scope->project))) ?? [];

        return array_intersect_key($values, array_flip($names));
    }

    public function purge(SecretScope $scope): void
    {
        if (! $this->ready($scope)) {
            return;
        }
        $client = $this->client($scope);
        $this->call($scope, function () use ($client, $scope) {
            $client->kvDestroy(OpenBaoAccess::projectPath($scope->project));
            OpenBaoAccess::forgetProject($client, $scope->project);
        });
    }

    public function envEntry(SecretScope $scope, ProjectSecret $secret): array
    {
        return [
            'name' => $secret->name,
            'valueFrom' => ['secretKeyRef' => [
                'name' => $scope->openBaoName,
                'key' => $secret->name,
            ]],
        ];
    }

    public function manifests(SecretScope $scope, Collection $secrets): array
    {
        if ($secrets->isEmpty() || ! $scope->hasCluster()) {
            return [];
        }

        $settings = RegionSecretSetting::forRegion($scope->region);
        $store = OpenBaoAccess::accountName($scope->project);
        $data = [];
        foreach ($secrets as $secret) {
            $data[] = [
                'secretKey' => $secret->name,
                'remoteRef' => ['key' => OpenBaoAccess::projectPath($scope->project), 'property' => $secret->name],
            ];
        }

        return array_merge(OpenBaoAccess::workloadManifests($scope, $settings), [[
            'apiVersion' => ExternalSecretManifests::API_VERSION,
            'kind' => 'ExternalSecret',
            'metadata' => [
                'name' => $scope->openBaoName,
                'namespace' => $scope->namespace,
                'labels' => $scope->labels([ExternalSecretManifests::LABEL => 'openbao']),
                'annotations' => $scope->annotations(),
            ],
            'spec' => [
                // A change through RunR is synced at once (forceSync()); this catches the rest
                'refreshInterval' => '300s',
                'secretStoreRef' => ['name' => $store, 'kind' => 'SecretStore'],
                'target' => [
                    'name' => $scope->openBaoName,
                    'creationPolicy' => 'Owner',
                    'template' => ['metadata' => ['labels' => $scope->labels([ExternalSecretManifests::LABEL => 'openbao'])]],
                ],
                'data' => $data,
            ],
        ]]);
    }

    /**
     * The project's access in OpenBao (its policy and role, with the credentials of its dynamic
     * bindings), its values checked — a value missing in OpenBao fails the deploy with its name —
     * and keys of removed variables dropped. A value the database holds again (a copy RunR 0.5.10
     * changed after a rollback, ProjectSecret::hasRollbackCopy()) is written first, as the region
     * backend does. Without variables here, a previous ExternalSecret goes.
     */
    public function prepareDeploy(SecretScope $scope, Collection $secrets, array $runrValues): void
    {
        if ($secrets->isEmpty()) {
            if ($scope->hasCluster() && RegionSecretSetting::forRegion($scope->region)->eso_status !== RegionSecretSetting::ESO_NOT_INSTALLED) {
                try {
                    (new KubernetesClient($scope->kubeconfig()))->deleteResource(ExternalSecretManifests::API_VERSION, 'ExternalSecret', $scope->openBaoName, $scope->namespace);
                } catch (\Throwable) {
                    // best effort: the CRD may be gone with the operator
                }
            }

            return;
        }

        if (! $scope->hasCluster()) {
            throw SecretBackendException::noCluster($scope->region?->name);
        }
        $settings = RegionSecretSetting::forRegion($scope->region);
        if (! $settings->esoReady()) {
            throw new SecretBackendException(SecretBackendException::NOT_INSTALLED, __('api.secret.eso_not_installed', ['region' => $scope->region?->name]));
        }

        $client = $this->client($scope);
        $names = $secrets->pluck('name')->all();
        $fromDatabase = [];
        foreach ($secrets as $secret) {
            if ($secret->isHeldInDatabase()) {
                $fromDatabase[$secret->name] = (string) $secret->value;
            }
        }
        $this->call($scope, function () use ($client, $scope, $names, $fromDatabase) {
            OpenBaoAccess::ensureProject($client, $scope, DynamicDbCredentials::rolesOf($scope->project));

            $current = $client->kvRead(OpenBaoAccess::projectPath($scope->project)) ?? [];
            $set = array_filter($fromDatabase, fn ($value, $name) => ($current[$name] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
            if ($set !== []) {
                $client->kvPatch(OpenBaoAccess::projectPath($scope->project), $set);
                $current = array_merge($current, $set);
            }
            $missing = array_values(array_diff($names, array_keys($current)));
            if ($missing !== []) {
                throw SecretBackendException::missing($scope->region?->name, $missing);
            }
            $stale = array_values(array_diff(array_keys($current), $names));
            if ($stale !== []) {
                $client->kvPatch(OpenBaoAccess::projectPath($scope->project), array_fill_keys($stale, null));
            }
        });
    }

    public function teardownTargets(SecretScope $scope, bool $withValues): array
    {
        // The Secret it filled is owned by it and goes with it; the values stay in OpenBao until
        // the project goes (purge())
        return [[ExternalSecretManifests::API_VERSION, 'ExternalSecret', $scope->openBaoName]];
    }

    /** OpenBao runs in the scope's region. */
    private function ready(SecretScope $scope): bool
    {
        return $scope->hasCluster() && RegionSecretSetting::forRegion($scope->region)->openbaoReady();
    }

    /** @throws SecretBackendException */
    private function client(SecretScope $scope): OpenBaoClient
    {
        if (! $scope->hasCluster()) {
            throw SecretBackendException::noCluster($scope->region?->name);
        }
        if (! RegionSecretSetting::forRegion($scope->region)->openbaoReady()) {
            throw new SecretBackendException(SecretBackendException::NOT_INSTALLED, __('api.secret.openbao_not_installed', ['region' => $scope->region?->name]));
        }

        return app(OpenBaoClients::class)->for($scope);
    }

    /**
     * Run an OpenBao call; anything but a SecretBackendException becomes "the region does not
     * answer" (OpenBao's error text is in the previous exception, never a value).
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function call(SecretScope $scope, callable $call): mixed
    {
        try {
            return $call();
        } catch (SecretBackendException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw SecretBackendException::unreachable($scope->region?->name, $e);
        }
    }

    /** The ExternalSecret reads the new values now instead of at its next refresh (best effort). */
    private function forceSync(SecretScope $scope): void
    {
        try {
            (new KubernetesClient($scope->kubeconfig()))->mergePatchOrFail(ExternalSecretManifests::API_VERSION, 'ExternalSecret', $scope->openBaoName, $scope->namespace, [
                'metadata' => ['annotations' => ['force-sync' => (string) now()->getTimestamp()]],
            ]);
        } catch (\Throwable $e) {
            Log::info('OpenBao values: ExternalSecret not asked to sync', ['project' => $scope->project->slug, 'error' => $e->getMessage()]);
        }
    }
}
