<?php

namespace App\Services\Secrets;

use App\Services\KubernetesClient;

/**
 * Reads and writes the keys of a project's values Secret (<slug>-secret-env) on a region cluster:
 * the only place a value RunR wrote lives (docs/security.md). Values pass through memory only —
 * they are never logged, cached or put into an exception; errors carry the HTTP status and the
 * API's message.
 *
 * Keys are changed one by one with a JSON merge patch (null removes a key), never by applying the
 * whole Secret: a deploy's server-side apply would drop keys it did not write. The Secret is
 * created on the first write with the project's labels (the tenant deletion sweep finds it).
 *
 * The container binds this class; tests replace it with an in-memory fake (Tests\Fakes\FakeRegionSecrets).
 */
class RegionSecrets
{
    /**
     * The keys of the Secret, name => value; null when the Secret does not exist.
     *
     * @return array<string, string>|null
     *
     * @throws SecretBackendException the cluster did not answer
     */
    public function read(SecretScope $scope): ?array
    {
        return $this->readFrom($scope->kubeconfig(), $scope->namespace, $scope->secretName, $scope->region?->name);
    }

    /**
     * Set keys (name => value) and remove keys (names) of the scope's Secret.
     *
     * @param  array<string, string>  $values
     * @param  list<string>  $remove
     *
     * @throws SecretBackendException
     */
    public function write(SecretScope $scope, array $values, array $remove = []): void
    {
        $this->writeTo($scope->kubeconfig(), $scope->namespace, $scope->secretName, $values, $remove, $scope->labels(), $scope->annotations(), $scope->region?->name);
    }

    /** @throws SecretBackendException */
    public function delete(SecretScope $scope): void
    {
        try {
            if (! $this->client($scope->kubeconfig())->deleteResource('v1', 'Secret', $scope->secretName, $scope->namespace)) {
                throw new \RuntimeException("deleting Secret {$scope->namespace}/{$scope->secretName} failed");
            }
        } catch (\Throwable $e) {
            throw SecretBackendException::unreachable($scope->region?->name, $e);
        }
    }

    /**
     * The keys of a Secret on any cluster (a region move reads the cluster it leaves).
     *
     * @return array<string, string>|null
     *
     * @throws SecretBackendException
     */
    public function readFrom(string $kubeconfig, string $namespace, string $name, ?string $region = null): ?array
    {
        try {
            $secret = $this->client($kubeconfig)->getResourceOrFail('v1', 'Secret', $name, $namespace);
        } catch (\Throwable $e) {
            throw SecretBackendException::unreachable($region, $e);
        }
        if ($secret === null) {
            return null;
        }

        $values = [];
        foreach ((array) ($secret['data'] ?? []) as $key => $encoded) {
            $decoded = base64_decode((string) $encoded, true);
            $values[(string) $key] = $decoded === false ? '' : $decoded;
        }

        return $values;
    }

    /**
     * Set and remove keys of a Secret on any cluster, creating it with $labels when it is missing.
     *
     * @param  array<string, string>  $values
     * @param  list<string>  $remove
     *
     * @throws SecretBackendException
     */
    public function writeTo(string $kubeconfig, string $namespace, string $name, array $values, array $remove = [], array $labels = [], array $annotations = [], ?string $region = null): void
    {
        if ($values === [] && $remove === []) {
            return;
        }

        $data = [];
        foreach ($values as $key => $value) {
            $data[(string) $key] = base64_encode((string) $value);
        }
        foreach ($remove as $key) {
            if (! array_key_exists($key, $data)) {
                $data[$key] = null;
            }
        }

        try {
            $k8s = $this->client($kubeconfig);
            $patch = ['metadata' => ['labels' => $labels ?: null], 'data' => $data];
            if ($labels === []) {
                unset($patch['metadata']);
            }

            if ($k8s->mergePatchOrFail('v1', 'Secret', $name, $namespace, $patch)) {
                return;
            }

            // No Secret yet: created with the keys to set (nothing to remove from a new one)
            $created = array_filter($data, fn ($v) => $v !== null);
            if ($created === []) {
                return;
            }
            $manifest = [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => array_filter([
                    'name' => $name,
                    'namespace' => $namespace,
                    'labels' => $labels ?: null,
                    'annotations' => $annotations ?: null,
                ]),
                'type' => 'Opaque',
                'data' => $created,
            ];
            if (! $k8s->createResourceOrFail($manifest, $namespace)) {
                // Created by a concurrent write in between: patch it
                $k8s->mergePatchOrFail('v1', 'Secret', $name, $namespace, ['data' => $data]);
            }
        } catch (\Throwable $e) {
            throw SecretBackendException::unreachable($region, $e);
        }
    }

    protected function client(string $kubeconfig): KubernetesClient
    {
        return new KubernetesClient($kubeconfig);
    }
}
