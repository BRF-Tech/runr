<?php

namespace App\Services\Concerns;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectSecret;
use App\Services\Environments\EnvironmentProject;
use App\Services\GlobalProjects\GlobalProjectRegions;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\Secrets\SecretBackendException;
use App\Services\Secrets\SecretBackends;
use App\Services\Secrets\SecretScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Secret environment variables of a project (docs/security.md, docs/secret-stores.md). RunR keeps
 * their metadata; the values live in the region (App\Services\Secrets\SecretBackends):
 *
 *  - values RunR wrote: the Secret <slug>-secret-env in the region's project namespace, a key per
 *    variable — written by the secrets API, never through a deploy manifest. The same Secret
 *    carries RunR's own keys of the workload: the signing key of a durable workflows project
 *    (KnativeWorkflowTrait), the gateway key of a hosted MCP server (KnativeMcpServerTrait), the
 *    topic publish token (App\Triggers\Topics\KnativeTopicsTrait) and the project's token on the
 *    region's internal network (RUNR_INTERNAL_TOKEN, docs/internal-network.md);
 *  - references to a secret store: the ExternalSecret <slug>-ext-env, which External Secrets
 *    Operator turns into the Secret of that name.
 *
 * The container gets every variable as a secretKeyRef env entry — the values never appear in the
 * Knative Service or CronJob spec. Plain env vars keep their `value` entries.
 *
 * Lifecycle: every deploy runs prepareSecretEnv() before it applies (RunR's own keys and values
 * not moved out of the database yet are written, keys of removed variables dropped, a value
 * missing in the region fails the deploy) and applies secretEnvManifests(). The values Secret is
 * deleted by the deploy of a project that has nothing left in it, by the project's deletion and
 * by the tenant deletion sweep (runr/tenant label) — never by stopping the project or deleting a
 * deployment: the region holds the only copy.
 */
trait KnativeSecretEnvTrait
{
    private function secretEnvName(Project $project): string
    {
        return SecretScope::secretEnvName((string) $project->slug);
    }

    private function externalEnvName(Project $project): string
    {
        return SecretScope::externalEnvName((string) $project->slug);
    }

    /**
     * RunR's own keys of the project's values Secret, name => value: the signing key of a durable
     * workflows project, the gateway key of a hosted MCP server, the topic publish token and the
     * project's token on the internal network (RUNR_INTERNAL_TOKEN). A deploy writes them and
     * keeps them: they are not dropped with the keys of removed variables.
     *
     * @return array<string, string>
     */
    private function runrSecretEnv(Project $project): array
    {
        return array_merge($this->workflowSecretEnv($project), $this->mcpGatewaySecretEnv($project), $this->topicsSecretEnv($project), $this->internalNetworkSecretEnv($project));
    }

    /**
     * The project's token on its region's internal network: its internal name signed with the
     * region's key (InternalNetwork). None without a region RunR deploys to.
     *
     * @return array<string, string>
     */
    private function internalNetworkSecretEnv(Project $project): array
    {
        $region = $project->knativeRegion;
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return [];
        }

        return [InternalNetwork::ENV_TOKEN => InternalNetwork::tokenFor((string) $region->id, InternalNetwork::nameOf($project))];
    }

    /**
     * Container env of the internal network: RUNR_INTERNAL_TOKEN from the project's Secret and
     * RUNR_INTERNAL_NAME, the project's <project>.<organization> (its host is that name under
     * runr.internal).
     */
    private function withInternalNetworkEnv(array $container, Project $project): array
    {
        if (! $this->internalNetworkSecretEnv($project)) {
            return $container;
        }

        $env = array_values(array_filter(
            $container['env'] ?? [],
            fn (array $entry) => ! in_array($entry['name'] ?? null, [InternalNetwork::ENV_TOKEN, InternalNetwork::ENV_NAME], true),
        ));
        $env[] = ['name' => InternalNetwork::ENV_NAME, 'value' => InternalNetwork::nameOf($project)];
        $env[] = ['name' => InternalNetwork::ENV_TOKEN, 'valueFrom' => ['secretKeyRef' => [
            'name' => $this->secretEnvName($project),
            'key' => InternalNetwork::ENV_TOKEN,
        ]]];
        $container['env'] = $env;

        return $container;
    }

    /**
     * Objects the secret backends apply with the deploy (no values: an ExternalSecret and its
     * SecretStores).
     *
     * @return list<array>
     */
    private function secretEnvManifests(Project $project): array
    {
        $backends = app(SecretBackends::class);
        $scope = SecretScope::forProject($project);
        $manifests = [];

        foreach ($backends->partition($this->projectSecrets($project)) as $source => $secrets) {
            if ($secrets->isNotEmpty()) {
                array_push($manifests, ...$backends->for($source)->manifests($scope, $secrets));
            }
        }

        return $manifests;
    }

    /**
     * The region side of the deploy, before the manifests are applied (SecretBackend::prepareDeploy).
     *
     * @throws SecretBackendException the deploy cannot go on (its message says why)
     */
    private function prepareSecretEnv(Project $project): void
    {
        $backends = app(SecretBackends::class);
        $scope = SecretScope::forProject($project);
        $runr = $this->runrSecretEnv($project);

        foreach ($backends->partition($this->inheritedValues($project, $this->projectSecrets($project))) as $source => $secrets) {
            $backends->for($source)->prepareDeploy($scope, $secrets, $runr);
        }
    }

    /**
     * An environment's workload has its own values Secret (<workload>-secret-env,
     * docs/environments.md#secrets): its own variables are there already (written by the secrets
     * API), and the project's variables it inherits whose values RunR wrote — into the project's
     * Secret or into its region's OpenBao — are copied into it, as values RunR holds for this
     * deploy (in memory, not saved). They are read where the project keeps them, so an environment
     * in another region gets them too. A value the project's region does not have stays a name,
     * and the deploy fails with it.
     *
     * @param  Collection<int, ProjectSecret>  $secrets  projectSecrets()
     * @return Collection<int, ProjectSecret>
     */
    private function inheritedValues(Project $project, Collection $secrets): Collection
    {
        if ($this->secretEnvironmentOf($project) === null) {
            return $secrets;
        }

        /** @var Collection<string, ProjectSecret> $inherited */
        $inherited = $project->secrets->filter(fn (ProjectSecret $s) => self::inheritsSecretValue($s))->keyBy('name');
        if ($inherited->isEmpty()) {
            return $secrets;
        }

        /** @var Project $base */
        $base = $project instanceof EnvironmentProject ? $project->baseProject() : $project;
        $backends = app(SecretBackends::class);
        $scope = SecretScope::forProject($base);
        $values = [];
        foreach ($backends->partition($inherited->values()) as $source => $part) {
            if ($part->isNotEmpty() && $backends->for($source)->holdsValues()) {
                $values += $backends->for($source)->readValues($scope, $part->pluck('name')->map(fn ($n) => (string) $n)->all());
            }
        }

        return $secrets->map(fn (ProjectSecret $secret) => $inherited->has($secret->name) && array_key_exists($secret->name, $values)
            ? self::environmentSecretCopy($secret, (string) $values[$secret->name])
            : $secret)->values();
    }

    /** The environment a deploy builds the workload of (null: the project's own workload). */
    private function secretEnvironmentOf(Project $project): ?ProjectEnvironment
    {
        $environment = $project instanceof EnvironmentProject ? $project->deployEnvironment() : null;

        return $environment !== null && ! $environment->is_primary ? $environment : null;
    }

    /**
     * A project variable an environment inherits whose value RunR wrote into the project's region
     * (its Secret or its OpenBao). The environment's own variables are not saved ProjectSecrets
     * (EnvironmentConfig::asProjectSecret()); a value still in the database is written as it is.
     */
    private static function inheritsSecretValue(ProjectSecret $secret): bool
    {
        return $secret->exists && ! $secret->isExternal() && ! $secret->isHeldInDatabase();
    }

    /**
     * An inherited variable as one of the environment's values Secret (source region, not saved):
     * with the value copied for this deploy, or without one (the env entry and the check that it
     * is there).
     */
    private static function environmentSecretCopy(ProjectSecret $secret, ?string $value): ProjectSecret
    {
        $copy = new ProjectSecret(['project_id' => $secret->project_id, 'name' => $secret->name, 'value' => $value]);
        $copy->forceFill(['source' => ProjectSecret::SOURCE_REGION, 'version' => (int) ($secret->version ?: 1)]);
        $copy->syncOriginal();
        $copy->setRelation('store', null);

        return $copy;
    }

    /**
     * The [apiVersion, kind, name] objects of the project's secrets to delete with its workloads.
     *
     * @param  bool  $withValues  the project goes away: the values go too
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function secretEnvTeardownTargets(Project $project, bool $withValues): array
    {
        $targets = [];
        $scope = SecretScope::forProject($project);
        foreach (app(SecretBackends::class)->all() as $backend) {
            array_push($targets, ...$backend->teardownTargets($scope, $withValues));
        }

        return $targets;
    }

    /**
     * The project goes: what the backends keep of it outside Kubernetes objects (its values and
     * access in the region's OpenBao). Best effort, each backend on its own; false when one failed.
     */
    private function purgeSecretValues(Project $project): bool
    {
        $ok = true;
        // a global project's other regions hold copies of the values (GlobalReplicaDeployer)
        $scopes = [SecretScope::forProject($project)];
        foreach (GlobalProjectRegions::secondaries($project) as $row) {
            $region = $row->knativeRegion;
            if ($region && ! $region->is_demo && $region->kubeconfig) {
                $scopes[] = SecretScope::forProject($project, $region);
            }
        }
        foreach ($scopes as $scope) {
            foreach (app(SecretBackends::class)->all() as $source => $backend) {
                try {
                    $backend->purge($scope);
                } catch (\Throwable $e) {
                    $ok = false;
                    Log::warning('Secret values of a deleted project not purged', [
                        'project' => $project->slug, 'region' => $scope->region?->name, 'source' => $source, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $ok;
    }

    /**
     * Container env: every secret variable as a secretKeyRef entry of the Secret its backend
     * fills. A plain entry of the same name (not possible through the API, ProjectSecretController
     * / UpdateProjectRequest) is dropped.
     */
    private function withSecretEnv(array $container, Project $project): array
    {
        $secrets = $this->projectSecrets($project);
        if ($secrets->isEmpty()) {
            return $container;
        }

        $names = $secrets->pluck('name')->all();
        $env = array_values(array_filter(
            $container['env'] ?? [],
            fn (array $entry) => ! in_array($entry['name'] ?? null, $names, true),
        ));

        $backends = app(SecretBackends::class);
        $scope = SecretScope::forProject($project);
        foreach ($secrets as $secret) {
            $env[] = $backends->for($secret)->envEntry($scope, $secret);
        }

        $container['env'] = $env;

        return $container;
    }

    /**
     * The project's variables, stores loaded. On an environment's workload, every inherited value
     * RunR wrote — also one in the project's OpenBao — is read from the environment's own values
     * Secret, where the deploy copies it (inheritedValues()): the project's OpenBao identity and
     * values path stay the project's own workload's.
     *
     * @return Collection<int, ProjectSecret>
     */
    private function projectSecrets(Project $project): Collection
    {
        $project->loadMissing('secrets.store');
        $secrets = $project->secrets->values();
        if ($this->secretEnvironmentOf($project) === null) {
            return $secrets;
        }

        return $secrets->map(fn (ProjectSecret $secret) => self::inheritsSecretValue($secret) && ($secret->source ?: ProjectSecret::SOURCE_REGION) !== ProjectSecret::SOURCE_REGION
            ? self::environmentSecretCopy($secret, null)
            : $secret)->values();
    }
}
