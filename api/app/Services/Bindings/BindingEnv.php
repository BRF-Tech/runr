<?php

namespace App\Services\Bindings;

use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\Project;
use App\Services\Environments\EnvironmentScope;
use App\Services\GlobalProjects\Data\RegionalData;

/**
 * Env vars of bindings (docs/bindings.md "Environment variables"):
 *
 *  - kv       REDIS_URL, KV_PREFIX
 *  - object   S3_ENDPOINT, S3_BUCKET, S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY, S3_REGION, S3_FORCE_PATH_STYLE
 *  - postgres DATABASE_URL, PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD, PGSSLMODE
 *  - external the binding's own keys
 *
 * Each attachment prefixes them with its env_prefix (ORDERS_ → ORDERS_DATABASE_URL). A project's
 * attachments together form the Secret <project>-bindings, referenced from its Knative
 * Service / CronJob container through envFrom (apply(), the one hook in KnativeManifestTrait).
 * Explicit env vars of the project win over envFrom values with the same name (Kubernetes).
 *
 * A Postgres attachment with dynamic credentials (DynamicDbCredentials) has no user, password or
 * DATABASE_URL in <project>-bindings: they come from its own Secret <project>-pgdyn-<…>, filled
 * in the region from OpenBao and referenced after <project>-bindings.
 *
 * Environments (docs/environments.md): a binding is attached to the project's own workload or to
 * one environment (binding_attachments.environment_id). Given an environment's view
 * (EnvironmentProject) everything here is the environment's — its attachments only (nothing is
 * inherited from the project's own workload), its Secret <workload>-bindings.
 */
class BindingEnv
{
    public const KEYS = [
        'kv' => ['REDIS_URL', 'KV_PREFIX'],
        'object' => ['S3_ENDPOINT', 'S3_BUCKET', 'S3_ACCESS_KEY_ID', 'S3_SECRET_ACCESS_KEY', 'S3_REGION', 'S3_FORCE_PATH_STYLE'],
        'postgres' => ['DATABASE_URL', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'],
    ];

    /**
     * What a dedicated Postgres binding of a global project adds in every region: the region's
     * own replica for reads (docs/global-projects.md, "Data").
     */
    public const GLOBAL_READ_KEYS = ['DATABASE_URL_RO', 'PGHOST_RO'];

    public const SECRET_SUFFIX = '-bindings';

    public const HASH_ANNOTATION = 'runr.dev/bindings-hash';

    public const ENV_NAME_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    /** @return list<string> unprefixed env keys of a binding */
    public static function keysFor(Binding $binding): array
    {
        if ($binding->type === 'external') {
            return array_values(array_map('strval', array_keys((array) $binding->credential('values', []))));
        }

        return self::KEYS[$binding->type] ?? [];
    }

    public static function secretName(Project $project): string
    {
        return $project->slug.self::SECRET_SUFFIX;
    }

    public static function hasAttachments(Project $project): bool
    {
        return $project->getKey() !== null
            && self::tablesReady()
            && EnvironmentScope::constrain(BindingAttachment::query(), $project)->exists();
    }

    /** @var array<string, bool> */
    private static array $tables = [];

    /**
     * The bindings tables exist on the current connection (a database migrated before the
     * bindings release — or a partial test schema — has none: nothing is bound there).
     */
    public static function tablesReady(): bool
    {
        $connection = (new BindingAttachment)->getConnection();
        $key = $connection->getName().':'.$connection->getDatabaseName();

        return self::$tables[$key] ??= $connection->getSchemaBuilder()->hasTable('binding_attachments');
    }

    /**
     * The project's env from its attachments (bindings that have credentials).
     *
     * @return array<string, string>
     */
    public static function projectEnv(Project $project, ?BindingDrivers $drivers = null): array
    {
        $drivers ??= app(BindingDrivers::class);
        $env = [];

        foreach (EnvironmentScope::attachments($project) as $attachment) {
            $binding = $attachment->binding;
            if (! $binding || ! $binding->isActive() || empty($binding->credentials)) {
                continue;
            }
            $dynamic = ($attachment->getAttribute('credential_mode') ?? DynamicDbCredentials::MODE_STATIC) === DynamicDbCredentials::MODE_DYNAMIC;
            $values = $drivers->for($binding)->env($binding);
            // A global project's copy in another region than the binding's reaches it through the
            // binding's data link, reads a dedicated Postgres binding's own replica there
            // ($project: the view of the region, knative_region_id = that region)
            if ($project->isGlobal() && $binding->type !== 'external' && EnvironmentScope::idOf($project) === null) {
                $values = app(RegionalData::class)->regionEnv($binding, $values, $project->knativeRegion);
            }
            foreach ($values as $key => $value) {
                // Issued by OpenBao for a dynamic attachment: never the binding's own user
                if ($dynamic && in_array($key, DynamicDbCredentials::DYNAMIC_KEYS, true)) {
                    continue;
                }
                $env[$attachment->env_prefix.$key] = (string) $value;
            }
        }

        return $env;
    }

    /** Stable hash of an env map (Secret annotation, revision trigger). */
    public static function hash(array $env): string
    {
        ksort($env);

        return substr(hash('sha256', json_encode($env, JSON_UNESCAPED_SLASHES)), 0, 16);
    }

    /**
     * Container hook: envFrom the project's bindings Secret when the project has attachments
     * (optional — a pod still starts while the Secret is being written). Other envFrom entries
     * stay as they are.
     */
    public static function apply(array $container, Project $project): array
    {
        if (! self::hasAttachments($project)) {
            return $container;
        }

        $container = self::withEnvFrom($container, self::secretName($project), true);
        foreach (DynamicDbCredentials::secretNames($project) as $secret) {
            $container = self::withEnvFrom($container, $secret, true);
        }

        return $container;
    }

    /**
     * $container with exactly $secrets as the envFrom entries of the project's dynamic credentials
     * (<project>-pgdyn-…), after its other entries.
     *
     * @param  list<string>  $secrets
     */
    public static function withDynamicEnvFrom(array $container, Project $project, array $secrets): array
    {
        $prefix = $project->slug.'-pgdyn-';
        $entries = array_values(array_filter(
            $container['envFrom'] ?? [],
            fn ($e) => ! str_starts_with((string) ($e['secretRef']['name'] ?? ''), $prefix),
        ));
        foreach ($secrets as $secret) {
            $entries[] = ['secretRef' => ['name' => $secret, 'optional' => true]];
        }

        if ($entries) {
            $container['envFrom'] = $entries;
        } else {
            unset($container['envFrom']);
        }

        return $container;
    }

    /** $container with (or without) the envFrom entry of $secret. */
    public static function withEnvFrom(array $container, string $secret, bool $present): array
    {
        $entries = array_values(array_filter(
            $container['envFrom'] ?? [],
            fn ($e) => ($e['secretRef']['name'] ?? null) !== $secret,
        ));

        if ($present) {
            $entries[] = ['secretRef' => ['name' => $secret, 'optional' => true]];
        }

        if ($entries) {
            $container['envFrom'] = $entries;
        } else {
            unset($container['envFrom']);
        }

        return $container;
    }
}
