<?php

namespace App\Services\Secrets;

use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\RegionSecretSetting;
use App\Models\SecretStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Writes, removes and reads the secret variables of a project (docs/security.md, "Secret
 * environment variables"; docs/secret-stores.md) — the one path the API, the project config
 * (config.secret_env_vars) and the CLI / MCP tools go through.
 *
 * Pass-through (config secrets.storage = region, the default): a value goes straight into the
 * region (RegionSecretBackend) first; only then is the metadata saved — name, version, who and when,
 * a keyed fingerprint. A region that does not answer fails the request and changes nothing.
 * Until the upgrade from 0.5.10 is finalized the row also keeps an encrypted copy of the value for
 * a rollback (RollbackCopies), and references are refused.
 * With secrets.storage = database the value is kept encrypted in project_secrets as RunR did before
 * pass-through, and written into the region by the next deploy.
 *
 * A reference (external) names a secret store of the organization, a path and a key; RunR never
 * sees its value.
 */
class ProjectSecrets
{
    public const STORAGE_REGION = 'region';

    public const STORAGE_DATABASE = 'database';

    public function __construct(private readonly SecretBackends $backends) {}

    /** Where new values go: region (default) or database (deprecated). */
    public static function storage(): string
    {
        return config('secrets.storage') === self::STORAGE_DATABASE ? self::STORAGE_DATABASE : self::STORAGE_REGION;
    }

    /**
     * The backend new values of a project go to in a region: its OpenBao while it runs there and
     * takes them (region_secret_settings.openbao_default), the region's Kubernetes Secret otherwise.
     */
    public static function valueSource(?KnativeRegion $region): string
    {
        return $region !== null && RegionSecretSetting::forRegion($region)->valuesInOpenBao()
            ? ProjectSecret::SOURCE_OPENBAO
            : ProjectSecret::SOURCE_REGION;
    }

    /**
     * Set values (name => value): into the region, then the metadata.
     *
     * @param  array<string, string>  $values
     * @param  KnativeRegion|null  $region  the region the values go to when it is not the project's
     *                                      own yet (a project update that also moves it)
     * @return list<string> the names set
     *
     * @throws SecretBackendException nothing was changed
     */
    public function setValues(Project $project, array $values, mixed $userId, ?KnativeRegion $region = null): array
    {
        if ($values === []) {
            return [];
        }

        $values = array_map('strval', $values);
        $inDatabase = self::storage() === self::STORAGE_DATABASE;
        $source = $inDatabase ? ProjectSecret::SOURCE_REGION : self::valueSource($region ?? $project->knativeRegion);
        if (! $inDatabase) {
            $this->backends->for($source)->write(SecretScope::forProject($project, $region), $values);
        }
        // The copy RunR 0.5.10 would read after a rollback (RollbackCopies)
        $keepCopy = ! $inDatabase && RollbackCopies::kept();

        $existing = $project->secrets()->whereIn('name', array_keys($values))->get()->keyBy('name');

        $project->getConnection()->transaction(function () use ($project, $values, $userId, $inDatabase, $keepCopy, $existing, $source) {
            foreach ($values as $name => $value) {
                /** @var ProjectSecret $row */
                $row = $existing[$name] ?? new ProjectSecret(['project_id' => $project->id, 'name' => (string) $name]);
                $row->fill([
                    'value' => $inDatabase || $keepCopy ? $value : null,
                    'rollback_copy' => $keepCopy,
                    'source' => $source,
                    'version' => $row->exists ? ((int) $row->version) + 1 : 1,
                    'fingerprint' => SecretFingerprint::of($value),
                    'secret_store_id' => null,
                    'remote_path' => null,
                    'remote_key' => null,
                    'sync_status' => null,
                    'sync_message' => null,
                    'synced_at' => null,
                    'updated_by' => $userId,
                ])->save();
            }
        });

        return array_map('strval', array_keys($values));
    }

    /**
     * Make a variable a reference to a secret store of the organization. A value the variable had
     * in the region stays there until the next deploy drops it (the workload reads it until then).
     * Refused until the upgrade from 0.5.10 is finalized (RollbackCopies).
     *
     * @throws SecretBackendException upgrade_not_finalized
     */
    public function setReference(Project $project, string $name, SecretStore $store, string $path, ?string $key, mixed $userId): ProjectSecret
    {
        RollbackCopies::assertReferencesAllowed();

        /** @var ProjectSecret $row */
        $row = $project->secrets()->where('name', $name)->first() ?? new ProjectSecret(['project_id' => $project->id, 'name' => $name]);
        $row->fill([
            'value' => null,
            'rollback_copy' => false,
            'source' => ProjectSecret::SOURCE_EXTERNAL,
            'version' => $row->exists ? ((int) $row->version) + 1 : 1,
            'fingerprint' => null,
            'secret_store_id' => $store->id,
            'remote_path' => $path,
            'remote_key' => $key !== null && $key !== '' ? $key : null,
            'sync_status' => ProjectSecret::SYNC_PENDING,
            'sync_message' => null,
            'synced_at' => null,
            'updated_by' => $userId,
        ])->save();

        return $row;
    }

    /**
     * Remove variables. A value in the region goes now when nothing of the project runs (no live
     * deployment reads it); otherwise with the next deploy — a pod the running revision starts
     * meanwhile still finds it. A region that does not answer leaves the value to that deploy.
     *
     * @param  list<string>  $names
     * @return list<string> the names removed
     */
    public function remove(Project $project, array $names): array
    {
        if ($names === []) {
            return [];
        }

        /** @var Collection<int, ProjectSecret> $rows */
        $rows = $project->secrets()->whereIn('name', $names)->get();
        if ($rows->isEmpty()) {
            return [];
        }

        if (! $project->deployments()->whereIn('status', Deployment::LIVE)->exists()) {
            $scope = SecretScope::forProject($project);
            foreach ($this->backends->partition($rows) as $source => $part) {
                $inRegion = $part->reject(fn (ProjectSecret $s) => $s->isHeldInDatabase())->pluck('name')->all();
                if ($inRegion === []) {
                    continue;
                }
                try {
                    $this->backends->for($source)->remove($scope, array_values($inRegion));
                } catch (SecretBackendException $e) {
                    Log::warning('Secret values left in the region until the next deploy', [
                        'project' => $project->slug, 'names' => $inRegion, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $removed = [];
        $project->getConnection()->transaction(function () use ($rows, &$removed) {
            foreach ($rows as $row) {
                // Model delete, so the audit trail records the removal (name only)
                if ($row->delete()) {
                    $removed[] = (string) $row->name;
                }
            }
        });

        return $removed;
    }

    /**
     * The value of a variable, read from where it lives, and whether it is the value RunR wrote
     * (its fingerprint; null when there is none to compare with).
     *
     * @return array{value: string, matches_fingerprint: bool|null}
     *
     * @throws SecretBackendException
     */
    public function reveal(Project $project, ProjectSecret $secret): array
    {
        $value = $this->backends->for($secret)->read(SecretScope::forProject($project), $secret);

        return [
            'value' => $value,
            'matches_fingerprint' => $secret->fingerprint ? SecretFingerprint::matches($value, $secret->fingerprint) : null,
        ];
    }

    /**
     * The values RunR wrote for a project, copied from one region to another — the project moves
     * to $to (PUT /projects/{project} with another knative_region_id). They go where $to keeps new
     * values (its OpenBao or its Kubernetes Secret, valueSource()) and the variables follow; in
     * memory only. The region it leaves keeps its copy while workloads there may read it; the
     * project's teardown there removes it.
     *
     * @return int how many values were copied
     *
     * @throws SecretBackendException the region it leaves did not answer, or a value is not there
     */
    public function copyToRegion(Project $project, KnativeRegion $from, KnativeRegion $to): int
    {
        $rows = $project->secrets()->get()
            ->filter(fn (ProjectSecret $s) => ! $s->isExternal() && ! $s->isHeldInDatabase());
        if ($rows->isEmpty()) {
            return 0;
        }

        $source = SecretScope::forProject($project, $from);
        $target = SecretScope::forProject($project, $to);
        if (! $source->hasCluster()) {
            throw SecretBackendException::noCluster($from->name);
        }
        if (! $target->hasCluster()) {
            throw SecretBackendException::noCluster($to->name);
        }

        $values = [];
        foreach ($this->backends->partition($rows) as $kind => $part) {
            if ($part->isNotEmpty()) {
                $values += $this->backends->for($kind)->readValues($source, $part->pluck('name')->all());
            }
        }
        $missing = array_values(array_diff($rows->pluck('name')->all(), array_keys($values)));
        if ($missing !== []) {
            throw SecretBackendException::missing($from->name, $missing);
        }

        $into = self::valueSource($to);
        $this->backends->for($into)->write($target, $values);
        unset($values);

        ProjectSecret::query()->whereKey($rows->modelKeys())->where('source', '!=', $into)->update(['source' => $into]);

        return $rows->count();
    }

    /**
     * The values RunR wrote for a global project, copied from its primary region into another of
     * its regions (GlobalReplicaDeployer, docs/global-projects.md#secrets): each into the same kind
     * of store there as the variable's source names (the region's Kubernetes Secret, or its
     * OpenBao) — every region of the project holds its own copy, the variables stay as they are.
     *
     * @return int how many values were copied
     *
     * @throws SecretBackendException a region did not answer, or a value is not in the primary
     */
    public function copyToReplica(Project $project, KnativeRegion $from, KnativeRegion $to): int
    {
        $rows = $project->secrets()->get()
            ->filter(fn (ProjectSecret $s) => ! $s->isExternal() && ! $s->isHeldInDatabase());
        if ($rows->isEmpty()) {
            return 0;
        }

        $source = SecretScope::forProject($project, $from);
        $target = SecretScope::forProject($project, $to);
        if (! $source->hasCluster()) {
            throw SecretBackendException::noCluster($from->name);
        }
        if (! $target->hasCluster()) {
            throw SecretBackendException::noCluster($to->name);
        }

        foreach ($this->backends->partition($rows) as $kind => $part) {
            if ($part->isEmpty()) {
                continue;
            }
            $backend = $this->backends->for($kind);
            $names = $part->pluck('name')->map(fn ($n) => (string) $n)->all();
            $values = $backend->readValues($source, $names);
            $missing = array_values(array_diff($names, array_keys($values)));
            if ($missing !== []) {
                throw SecretBackendException::missing($from->name, $missing);
            }
            $backend->write($target, $values);
            unset($values);
        }

        return $rows->count();
    }
}
