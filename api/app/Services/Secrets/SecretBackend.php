<?php

namespace App\Services\Secrets;

use App\Models\ProjectSecret;
use Illuminate\Support\Collection;

/**
 * Where the value of a project secret variable lives (project_secrets.source), and how it
 * reaches the workload. RunR's database keeps metadata only; a backend keeps or finds the value:
 *
 *  - region   (RegionSecretBackend)   — RunR writes the value into the region's Kubernetes Secret;
 *  - external (ExternalSecretBackend) — a reference to an organization's secret store, synced into
 *                                       the region by External Secrets Operator;
 *  - openbao  (OpenBaoSecretBackend)  — RunR writes the value into the region's own OpenBao, which
 *                                       External Secrets Operator syncs into the workload.
 *
 * SecretBackends holds them by source; a new backend (per-environment stores) registers there and
 * gets every call below with the scope of the workload (SecretScope).
 * No method returns a value except read(), and none may log one.
 */
interface SecretBackend
{
    /** The project_secrets.source of the variables this backend holds. */
    public function source(): string;

    /** Whether RunR writes values into this backend (false: the variables are references). */
    public function holdsValues(): bool;

    /**
     * Store values (name => value) in the backend. They pass through memory only.
     *
     * @param  array<string, string>  $values
     *
     * @throws SecretBackendException
     */
    public function write(SecretScope $scope, array $values): void;

    /**
     * Remove variables (names) from the backend now: their workloads no longer read them.
     *
     * @param  list<string>  $names
     *
     * @throws SecretBackendException
     */
    public function remove(SecretScope $scope, array $names): void;

    /**
     * The value of one variable (GET …/secrets/{name}/value).
     *
     * @throws SecretBackendException
     */
    public function read(SecretScope $scope, ProjectSecret $secret): string;

    /**
     * The values the backend holds for $names (name => value; a name without a value is left
     * out) — a region move copies them, the database move verifies them. Not for references.
     *
     * @param  list<string>  $names
     * @return array<string, string>
     *
     * @throws SecretBackendException
     */
    public function readValues(SecretScope $scope, array $names): array;

    /**
     * The project goes: everything of it the backend keeps outside Kubernetes objects (those are
     * teardownTargets()) — its values in OpenBao, its policy and role there.
     *
     * @throws SecretBackendException
     */
    public function purge(SecretScope $scope): void;

    /** The container env entry of a variable: always a secretKeyRef, never a value. */
    public function envEntry(SecretScope $scope, ProjectSecret $secret): array;

    /**
     * Manifests a deploy applies for the scope's variables of this backend — objects that carry
     * no value (an ExternalSecret, its SecretStore). Empty when there is nothing to apply.
     *
     * @param  Collection<int, ProjectSecret>  $secrets  the scope's variables of this backend
     * @return list<array>
     */
    public function manifests(SecretScope $scope, Collection $secrets): array;

    /**
     * What a deploy does in the region before it applies the manifests: e.g. put RunR's own keys
     * next to the values, drop keys of removed variables, check every value is there.
     *
     * @param  Collection<int, ProjectSecret>  $secrets  the scope's variables of this backend
     * @param  array<string, string>  $runrValues  RunR's own keys of the workload (workflow signing
     *                                             key, MCP gateway key) — the region backend keeps them
     *
     * @throws SecretBackendException the deploy cannot go on
     */
    public function prepareDeploy(SecretScope $scope, Collection $secrets, array $runrValues): void;

    /**
     * The [apiVersion, kind, name] objects of the scope to delete with its workloads.
     *
     * @param  bool  $withValues  the project goes away: the values go too (otherwise they stay for
     *                            the next deploy — the region keeps the only copy)
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function teardownTargets(SecretScope $scope, bool $withValues): array;
}
