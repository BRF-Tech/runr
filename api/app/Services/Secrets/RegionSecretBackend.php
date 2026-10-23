<?php

namespace App\Services\Secrets;

use App\Models\ProjectSecret;
use Illuminate\Support\Collection;

/**
 * Secret variables whose value RunR wrote into the project's region (source "region",
 * docs/security.md): one Kubernetes Secret per workload, <slug>-secret-env in the region's
 * project namespace, a key per variable. The value lives there and nowhere else; RunR keeps the
 * metadata. The same Secret carries RunR's own keys of the workload (RUNR_WORKFLOW_SIGNING_KEY,
 * RUNR_MCP_GATEWAY_KEY): those are RunR's operating credentials, kept in RunR's database because
 * RunR signs and verifies with them, and written here by every deploy.
 *
 * A row written before pass-through still holds its value in the database (isHeldInDatabase()):
 * a deploy writes it into the Secret as earlier releases did, until secrets:move-to-region moves it.
 */
class RegionSecretBackend implements SecretBackend
{
    public function source(): string
    {
        return ProjectSecret::SOURCE_REGION;
    }

    public function holdsValues(): bool
    {
        return true;
    }

    public function write(SecretScope $scope, array $values): void
    {
        $this->requireCluster($scope);
        $this->secrets()->write($scope, $values);
    }

    public function remove(SecretScope $scope, array $names): void
    {
        if ($names === [] || ! $scope->hasCluster()) {
            return;
        }
        $this->secrets()->write($scope, [], $names);
    }

    public function read(SecretScope $scope, ProjectSecret $secret): string
    {
        if ($secret->isHeldInDatabase()) {
            return (string) $secret->value;
        }

        $this->requireCluster($scope);
        $values = $this->secrets()->read($scope) ?? [];
        if (! array_key_exists($secret->name, $values)) {
            throw SecretBackendException::missing($scope->region?->name, [$secret->name]);
        }

        return $values[$secret->name];
    }

    public function readValues(SecretScope $scope, array $names): array
    {
        if ($names === [] || ! $scope->hasCluster()) {
            return [];
        }

        return array_intersect_key($this->secrets()->read($scope) ?? [], array_flip($names));
    }

    public function purge(SecretScope $scope): void
    {
        // The values Secret is one of teardownTargets()
    }

    public function envEntry(SecretScope $scope, ProjectSecret $secret): array
    {
        return [
            'name' => $secret->name,
            'valueFrom' => ['secretKeyRef' => [
                'name' => $scope->secretName,
                'key' => $secret->name,
            ]],
        ];
    }

    public function manifests(SecretScope $scope, Collection $secrets): array
    {
        // The values never go through a manifest: written key by key (RegionSecrets)
        return [];
    }

    /**
     * The Secret holds exactly the project's variables and RunR's own keys afterwards:
     *  - values still in the database and RunR's keys are written (when they differ);
     *  - keys of variables removed since the last deploy are dropped (a removal waits for the
     *    deploy while workloads read the variable, ProjectSecrets::remove());
     *  - a variable whose value is not in the region (lost with a cluster, removed by hand) fails
     *    the deploy with its name — the pods could not start without it;
     *  - nothing left to hold: the Secret goes.
     */
    public function prepareDeploy(SecretScope $scope, Collection $secrets, array $runrValues): void
    {
        $this->requireCluster($scope);

        $fromDatabase = [];
        $inRegion = [];
        foreach ($secrets as $secret) {
            if ($secret->isHeldInDatabase()) {
                $fromDatabase[$secret->name] = (string) $secret->value;
            } else {
                $inRegion[] = $secret->name;
            }
        }

        $wanted = array_merge(array_keys($fromDatabase), $inRegion, array_keys($runrValues));
        $current = $this->secrets()->read($scope);

        if ($wanted === []) {
            if ($current !== null) {
                $this->secrets()->delete($scope);
            }

            return;
        }

        $missing = array_values(array_diff($inRegion, array_keys($current ?? [])));
        if ($missing !== []) {
            throw SecretBackendException::missing($scope->region?->name, $missing);
        }

        $set = [];
        foreach (array_merge($fromDatabase, $runrValues) as $name => $value) {
            if (($current[$name] ?? null) !== $value) {
                $set[$name] = $value;
            }
        }
        $stale = array_values(array_diff(array_keys($current ?? []), $wanted));

        $this->secrets()->write($scope, $set, $stale);
    }

    public function teardownTargets(SecretScope $scope, bool $withValues): array
    {
        return $withValues ? [['v1', 'Secret', $scope->secretName]] : [];
    }

    private function requireCluster(SecretScope $scope): void
    {
        if (! $scope->hasCluster()) {
            throw SecretBackendException::noCluster($scope->region?->name);
        }
    }

    private function secrets(): RegionSecrets
    {
        return app(RegionSecrets::class);
    }
}
