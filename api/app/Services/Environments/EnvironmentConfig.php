<?php

namespace App\Services\Environments;

use App\Models\BindingAttachment;
use App\Models\EnvironmentSecret;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectSecret;
use App\Models\Version;
use App\Services\Bindings\BindingEnv;
use App\Services\Helpers\ResourceReservation;
use App\Services\Rollouts\DeployStrategy;
use App\Support\SchemaColumns;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Config layering of environments (docs/environments.md#configuration) and the config snapshot a
 * deployment records (docs/versioning.md#rollback):
 *
 *   project config (xaas / faas / container config)
 *     └─ the environment's overrides (ProjectEnvironment::OVERRIDABLE; env_vars merged by name,
 *        a null value removes the project's variable)
 *          └─ a deploy's overrides (deploy_config: cpu, memory_mb, scale, timeout — applied by the
 *             manifest builder, recorded on the deployment)
 *
 * Secret env vars: the project's (unless the environment does not inherit them) with the
 * environment's own over them. A queue trigger reads <stream>-<environment> unless the environment
 * names its own stream. Bindings are never inherited: an environment has only what is
 * attached to it, so staging cannot reach production's data.
 *
 * A snapshot is the effective config (every field of the project type's config, env var values
 * included — stored encrypted on the deployment) plus, for the "what will change" diff, the names
 * of the secret env vars and binding variables. A rollback restores its config fields; secret
 * values and bindings always stay the current ones (credentials are rotated forward, never back).
 * The image of a CaaS project is the version's code, not config: every deploy runs the image of
 * the version it deploys (versionImage()).
 */
final class EnvironmentConfig
{
    /** Config fields of each project type that a snapshot records and a rollback restores. */
    public const FIELDS = [
        'xaas' => ['filename', 'handler', 'env_vars', 'memory_mb', 'cpu', 'timeout_seconds', 'trigger_type', 'schedule', 'queue_stream', 'scale_to_zero', 'min_scale', 'max_scale', 'gpu'],
        'faas' => ['handler', 'env_vars', 'memory_mb', 'cpu', 'timeout_seconds', 'trigger_type', 'schedule', 'queue_stream', 'scale_to_zero', 'min_scale', 'max_scale', 'gpu'],
        'caas' => ['image', 'command', 'args', 'env_vars', 'ports', 'memory_mb', 'cpu', 'timeout_seconds', 'image_pull_policy', 'image_pull_secret', 'trigger_type', 'schedule', 'queue_stream', 'scale_to_zero', 'min_scale', 'max_scale', 'gpu'],
    ];

    /** Keys of a snapshot that are information only (diff), never restored. */
    public const META_KEYS = ['_environment', '_region_id', '_secret_names', '_binding_env'];

    public static function relationOf(Project $project): ?string
    {
        return match ($project->service_type) {
            'xaas' => 'xaasConfig',
            'faas' => 'faasConfig',
            'caas' => 'containerConfig',
            default => null,
        };
    }

    /** @return list<string> */
    public static function fieldsOf(Project $project): array
    {
        return self::FIELDS[$project->service_type] ?? [];
    }

    /**
     * The config model an environment's workload runs with: a copy of the project's config (never
     * saved) with the environment's overrides — or a restored snapshot — and the version's image.
     *
     * @param  array<string, mixed>|null  $restored
     */
    public static function effective(Project $project, ?ProjectEnvironment $environment = null, ?array $restored = null, ?string $image = null): ?Model
    {
        $relation = self::relationOf($project);
        if ($relation === null) {
            return null;
        }

        $stored = $project->relationLoaded($relation) ? $project->getRelation($relation) : $project->{$relation}()->first();
        if (! $stored instanceof Model) {
            return null;
        }

        $config = clone $stored;
        $values = $restored !== null
            ? array_intersect_key($restored, array_flip(self::fieldsOf($project)))
            : self::overridesFor($stored, $environment);
        if ($image !== null && $image !== '' && $project->service_type === 'caas') {
            $values['image'] = $image;
        }

        if ($values !== []) {
            $config->forceFill($values);
        }

        return $config;
    }

    /**
     * The environment's overrides as config fields (env_vars merged over the project's).
     *
     * @return array<string, mixed>
     */
    public static function overridesFor(Model $stored, ?ProjectEnvironment $environment): array
    {
        if ($environment === null || $environment->is_primary) {
            return [];
        }

        $overrides = $environment->overrideValues();
        if (array_key_exists('env_vars', $overrides)) {
            $overrides['env_vars'] = self::mergeEnv((array) ($stored->getAttribute('env_vars') ?? []), (array) $overrides['env_vars']);
        }

        // The deploy strategy the environment sets of its own, over the project's (the options it
        // does not set are the project's: docs/deploy-strategies.md#environments)
        if (array_key_exists('deploy_strategy', $overrides)) {
            $own = $overrides['deploy_strategy'];
            unset($overrides['deploy_strategy']);
            if (is_array($own) && $own !== [] && SchemaColumns::has($stored, 'deploy_strategy')) {
                $base = $stored->getAttribute('deploy_strategy');
                $overrides['deploy_strategy'] = DeployStrategy::fromArray(is_array($base) ? $base : null)->merge($own)->toArray();
            }
        }

        // A queue-triggered environment reads a stream of its own (<stream>-<environment>) unless
        // it names one: staging never consumes production's messages
        $stream = $stored->getAttribute('queue_stream');
        if (($overrides['queue_stream'] ?? null) === null && is_string($stream) && $stream !== '') {
            $overrides['queue_stream'] = $stream.'-'.$environment->name;
        }

        // An empty value keeps the project's (WorkloadSizing's rule); false / 0 are values
        return array_filter($overrides, fn ($value, string $field) => $field === 'env_vars' || ($value !== null && $value !== ''), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Env vars of an environment: the project's, then the environment's by name — a null value
     * removes the project's variable.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, string>
     */
    public static function mergeEnv(array $base, array $override): array
    {
        $env = [];
        foreach ($base as $name => $value) {
            $env[(string) $name] = (string) $value;
        }
        foreach ($override as $name => $value) {
            if ($value === null) {
                unset($env[(string) $name]);
            } else {
                $env[(string) $name] = (string) $value;
            }
        }

        return $env;
    }

    /**
     * Secret env vars an environment's workload gets: the project's (unless the environment does
     * not inherit them), the environment's own over them by name. An environment's own variable
     * comes as a ProjectSecret the secret backends take as they are (asProjectSecret()): a value in
     * the environment's region, in its Secret <workload>-secret-env (pass-through, written by the
     * secrets API; the deploy checks it is there).
     *
     * @return Collection<int, ProjectSecret>
     */
    public static function secrets(Project $project, ?ProjectEnvironment $environment = null): Collection
    {
        $base = $project->relationLoaded('secrets') && ! $project instanceof EnvironmentProject
            ? $project->secrets
            : ProjectSecret::query()->where('project_id', $project->getKey())->orderBy('name')->get();

        if ($environment === null || $environment->is_primary) {
            /** @var Collection<int, ProjectSecret> $base */
            return $base;
        }

        $own = EnvironmentSecret::query()->where('environment_id', $environment->getKey())->orderBy('name')->get();
        $byName = [];
        if ($environment->inherit_secrets) {
            foreach ($base as $secret) {
                $byName[$secret->name] = $secret;
            }
        }
        foreach ($own as $secret) {
            $byName[$secret->name] = self::asProjectSecret($secret);
        }
        ksort($byName);

        return new Collection(array_values($byName));
    }

    /**
     * An environment's own variable as the secret backends take a variable (not saved): source
     * region, its value in the region (none in RunR's database), no secret store.
     */
    public static function asProjectSecret(EnvironmentSecret $secret): ProjectSecret
    {
        $row = new ProjectSecret;
        $row->setRawAttributes([
            'id' => $secret->getKey(),
            'project_id' => $secret->project_id,
            'name' => $secret->name,
            'value' => null,
            'source' => ProjectSecret::SOURCE_REGION,
            'version' => (int) ($secret->version ?: 1),
            'fingerprint' => $secret->fingerprint,
            'secret_store_id' => null,
            'updated_by' => $secret->updated_by,
            'created_at' => $secret->getRawOriginal('created_at'),
            'updated_at' => $secret->getRawOriginal('updated_at'),
        ], true);
        $row->setRelation('store', null);

        return $row;
    }

    /**
     * The image of a version of a CaaS project: the image its config snapshot recorded (every
     * CaaS config change is a version); null for other types or a version without one.
     */
    public static function versionImage(Project $project, ?Version $version): ?string
    {
        if ($project->service_type !== 'caas' || $version === null) {
            return null;
        }

        $image = is_array($version->config_snapshot) ? ($version->config_snapshot['image'] ?? null) : null;

        return is_string($image) && $image !== '' ? $image : null;
    }

    /**
     * The snapshot of what a project view (EnvironmentProject, or the project itself) deploys
     * now: its effective config fields with values, and names of its secret env vars and
     * binding variables.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Project $project): array
    {
        $config = ResourceReservation::configOf($project);
        $snapshot = [];
        foreach (self::fieldsOf($project) as $field) {
            $snapshot[$field] = $config?->getAttribute($field);
        }

        $environment = $project instanceof EnvironmentProject ? $project->deployEnvironment() : null;
        $snapshot['_environment'] = $environment->name ?? ProjectEnvironment::PRIMARY_NAME;
        $snapshot['_region_id'] = $project->knative_region_id;
        $snapshot['_secret_names'] = $project->secrets->pluck('name')->values()->all();
        $snapshot['_binding_env'] = self::bindingEnvNames($project);

        return $snapshot;
    }

    /**
     * The config fields of a snapshot that a rollback restores.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function restorable(Project $project, array $snapshot): array
    {
        return array_intersect_key($snapshot, array_flip(self::fieldsOf($project)));
    }

    /**
     * What changes from snapshot $from to snapshot $to — the "what will change" of a promotion or
     * a rollback. Env vars and secrets by name only (added / removed / changed), never a value.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<array<string, mixed>>
     */
    public static function diff(array $from, array $to): array
    {
        $changes = [];
        $fields = array_values(array_unique(array_merge(array_keys($from), array_keys($to))));

        foreach ($fields as $field) {
            if (in_array($field, ['_environment', '_secret_names', '_binding_env'], true)) {
                continue;
            }
            $a = $from[$field] ?? null;
            $b = $to[$field] ?? null;

            if ($field === 'env_vars') {
                if ($names = self::nameDiff((array) ($a ?? []), (array) ($b ?? []))) {
                    $changes[] = ['field' => 'env_vars'] + $names;
                }

                continue;
            }

            if (self::normalize($a) !== self::normalize($b)) {
                $changes[] = ['field' => $field === '_region_id' ? 'region' : $field, 'from' => $a, 'to' => $b];
            }
        }

        foreach (['_secret_names' => 'secrets', '_binding_env' => 'bindings'] as $key => $label) {
            if (! array_key_exists($key, $from) && ! array_key_exists($key, $to)) {
                continue;
            }
            $before = array_map('strval', (array) ($from[$key] ?? []));
            $after = array_map('strval', (array) ($to[$key] ?? []));
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));
            if ($added || $removed) {
                $changes[] = ['field' => $label, 'added' => $added, 'removed' => $removed, 'changed' => []];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}|null
     */
    private static function nameDiff(array $before, array $after): ?array
    {
        $added = array_map('strval', array_keys(array_diff_key($after, $before)));
        $removed = array_map('strval', array_keys(array_diff_key($before, $after)));
        $changed = [];
        foreach (array_intersect_key($after, $before) as $name => $value) {
            if ((string) $value !== (string) $before[$name]) {
                $changed[] = (string) $name;
            }
        }
        sort($added);
        sort($removed);
        sort($changed);

        return $added || $removed || $changed ? ['added' => $added, 'removed' => $removed, 'changed' => $changed] : null;
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return json_encode($value);
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** @return list<string> env var names of the bindings the view's workload gets */
    private static function bindingEnvNames(Project $project): array
    {
        if (! BindingEnv::tablesReady()) {
            return [];
        }

        $names = [];
        $attachments = EnvironmentScope::attachments($project);
        foreach ($attachments as $attachment) {
            /** @var BindingAttachment $attachment */
            array_push($names, ...$attachment->envNames());
        }
        sort($names);

        return array_values(array_unique($names));
    }
}
