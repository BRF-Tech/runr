<?php

namespace App\Services\Environments;

use App\Models\Deployment;
use App\Models\EnvironmentSecret;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectSecret;
use App\Services\Secrets\RegionSecrets;
use App\Services\Secrets\SecretBackend;
use App\Services\Secrets\SecretBackendException;
use App\Services\Secrets\SecretBackends;
use App\Services\Secrets\SecretFingerprint;
use App\Services\Secrets\SecretScope;
use Illuminate\Support\Facades\Log;

/**
 * The secret env vars an environment has of its own (docs/environments.md#secrets), pass-through
 * like the project's (App\Services\Secrets\ProjectSecrets): a value goes straight into the
 * environment's region first — the Kubernetes Secret <workload>-secret-env of the environment's
 * workload, through the region secret backend — and only then is the metadata saved: name,
 * version, who and when, a keyed fingerprint. RunR's database never holds the value, and a region
 * that does not answer fails the request and changes nothing.
 *
 * The values Secret is the workload's own: each deploy adds the project's variables the
 * environment inherits (copied from where they live) and RunR's keys of the workload
 * (KnativeSecretEnvTrait); the environment's teardown removes it. The values follow the
 * environment when it, its project or its region moves to another cluster (copy()).
 */
class EnvironmentSecrets
{
    public function __construct(private readonly SecretBackends $backends) {}

    /** Where the environment's own values live: its workload's Secret in its region (or in $region). */
    public static function scope(Project $project, ProjectEnvironment $environment, ?KnativeRegion $region = null): SecretScope
    {
        return SecretScope::forProject(DeployTarget::environment($project, $environment), $region);
    }

    /**
     * Set a value: into the environment's region, then the metadata.
     *
     * @throws SecretBackendException nothing was changed
     */
    public function set(Project $project, ProjectEnvironment $environment, string $name, string $value, mixed $userId): EnvironmentSecret
    {
        $this->backend()->write(self::scope($project, $environment), [$name => $value]);

        /** @var EnvironmentSecret $secret */
        $secret = EnvironmentSecret::query()->firstOrNew(['environment_id' => $environment->id, 'name' => $name]);
        $secret->forceFill([
            'project_id' => $environment->project_id,
            'source' => ProjectSecret::SOURCE_REGION,
            'version' => $secret->exists ? ((int) $secret->version) + 1 : 1,
            'fingerprint' => SecretFingerprint::of($value),
            'updated_by' => $userId,
        ])->save();

        return $secret;
    }

    /**
     * Remove a variable. Its value leaves the region now when nothing of the environment runs;
     * otherwise with the environment's next deploy (a pod the running revision starts meanwhile
     * still finds it). A region that does not answer leaves the value to that deploy.
     */
    public function remove(Project $project, ProjectEnvironment $environment, string $name): bool
    {
        $secret = EnvironmentSecret::query()->where('environment_id', $environment->id)->where('name', $name)->first();
        if (! $secret) {
            return false;
        }

        if (! Deployment::query()->where('environment_id', $environment->id)->whereIn('status', Deployment::LIVE)->exists()) {
            try {
                $this->backend()->remove(self::scope($project, $environment), [$name]);
            } catch (SecretBackendException $e) {
                Log::warning('Environment secret value left in the region until the next deploy', [
                    'environment' => $environment->id, 'name' => $name, 'error' => $e->getMessage(),
                ]);
            }
        }

        return (bool) $secret->delete();
    }

    /**
     * The environment's own values, read from its region (or $region): name => value; a name
     * without a value there is left out. In memory only (org export, copies between clusters).
     *
     * @return array<string, string>
     *
     * @throws SecretBackendException
     */
    public function readValues(Project $project, ProjectEnvironment $environment, ?KnativeRegion $region = null): array
    {
        $names = $this->names($environment);
        if ($names === []) {
            return [];
        }

        return $this->backend()->readValues(self::scope($project, $environment, $region), $names);
    }

    /**
     * The environment's own values copied from one cluster to another: the environment moves to
     * another region, follows its project there, or its region moves to a new cluster. With
     * $forgetSource the Secret it leaves goes too (nothing of the environment runs there any more).
     *
     * @return int how many values were copied
     *
     * @throws SecretBackendException the cluster it leaves did not answer, or a value is not there
     */
    public function copy(Project $project, ProjectEnvironment $environment, KnativeRegion $from, KnativeRegion $to, bool $forgetSource = false): int
    {
        $names = $this->names($environment);
        if ($names === []) {
            return 0;
        }

        $source = self::scope($project, $environment, $from);
        $target = self::scope($project, $environment, $to);
        if (! $source->hasCluster()) {
            throw SecretBackendException::noCluster($from->name);
        }
        if (! $target->hasCluster()) {
            throw SecretBackendException::noCluster($to->name);
        }

        $values = $this->backend()->readValues($source, $names);
        $missing = array_values(array_diff($names, array_keys($values)));
        if ($missing !== []) {
            throw SecretBackendException::missing($from->name, $missing);
        }
        $this->backend()->write($target, $values);
        $count = count($values);
        unset($values);

        if ($forgetSource) {
            try {
                app(RegionSecrets::class)->delete($source);
            } catch (SecretBackendException $e) {
                Log::warning('Environment secret values left on the cluster the environment left', [
                    'environment' => $environment->id, 'region' => $from->name, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * The project moves from one region to another: its environments that follow the project's
     * region and run nothing follow it there (EnvironmentManager::projectMoved() keeps the region
     * of the ones that run), and their own values with them.
     *
     * @return int how many values were copied
     *
     * @throws SecretBackendException
     */
    public function followProject(Project $project, KnativeRegion $from, KnativeRegion $to): int
    {
        if (! ProjectEnvironment::tablesReady()) {
            return 0;
        }

        $copied = 0;
        $following = ProjectEnvironment::query()->where('project_id', $project->id)->where('is_primary', false)
            ->where(fn ($q) => $q->whereNull('knative_region_id')->orWhere('knative_region_id', ''))
            ->get();
        foreach ($following as $environment) {
            if (Deployment::query()->where('environment_id', $environment->id)->whereIn('status', Deployment::LIVE)->exists()) {
                continue;
            }
            $environment->setRelation('project', $project);
            $copied += $this->copy($project, $environment, $from, $to, true);
        }

        return $copied;
    }

    /** @return list<string> */
    private function names(ProjectEnvironment $environment): array
    {
        return EnvironmentSecret::query()->where('environment_id', $environment->id)->orderBy('name')
            ->pluck('name')->map(fn ($n) => (string) $n)->all();
    }

    private function backend(): SecretBackend
    {
        return $this->backends->for(ProjectSecret::SOURCE_REGION);
    }
}
