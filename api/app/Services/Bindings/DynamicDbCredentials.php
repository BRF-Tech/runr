<?php

namespace App\Services\Bindings;

use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\Project;
use App\Models\RegionSecretSetting;
use App\Services\Bindings\DataServices\PostgresService;
use App\Services\Bindings\Postgres\PostgresSql;
use App\Services\KubernetesClient;
use App\Services\Secrets\External\ExternalSecretManifests;
use App\Services\Secrets\OpenBao\OpenBaoAccess;
use App\Services\Secrets\OpenBao\OpenBaoClient;
use App\Services\Secrets\OpenBaoClients;
use App\Services\Secrets\SecretScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Dynamic Postgres credentials of bindings (docs/bindings.md, "Dynamic credentials"): a project
 * attached with credential_mode "dynamic" does not get the binding's own user and password but a
 * Postgres user of its own, issued by the region's OpenBao (database secrets engine) for a limited
 * time and renewed in the region:
 *
 *  - per binding a connection runr-<binding id> in OpenBao as a role runr_bao_<…> that may create
 *    members of the binding's role; RunR creates that role with a password it hands to OpenBao
 *    once and OpenBao rotates at once (rotate-root) — afterwards only OpenBao knows it;
 *  - per attachment a role runr-<binding id>-<project id>: CREATE ROLE … IN ROLE <binding role>,
 *    sessions switch to the binding's role (objects belong to it), revocation reassigns and drops;
 *  - in the region: External Secrets Operator's VaultDynamicSecret generator logs in as the
 *    project's service account (OpenBaoAccess) and the ExternalSecret <slug>-pgdyn-<…> renews the
 *    user every config secrets.openbao.db_refresh, filling <prefix>PGUSER, <prefix>PGPASSWORD and
 *    <prefix>DATABASE_URL; the static Secret <slug>-bindings keeps the other keys (host, port,
 *    database, sslmode). A renewal rolls the workload onto the new user (restartOnRenewal()); the
 *    previous user lives on until its TTL (db_default_ttl) ends.
 *
 * Postgres bindings only; needs the region's OpenBao and External Secrets Operator.
 */
class DynamicDbCredentials
{
    public const MODE_STATIC = 'static';

    public const MODE_DYNAMIC = 'dynamic';

    /** Keys a dynamic attachment gets from OpenBao instead of the binding's own user. */
    public const DYNAMIC_KEYS = ['DATABASE_URL', 'PGUSER', 'PGPASSWORD'];

    public const GENERATOR_API = 'generators.external-secrets.io/v1alpha1';

    /** Template annotation of the workload: the renewals it runs with (restartOnRenewal()). */
    public const RENEWAL_ANNOTATION = 'runr.dev/pgdyn-version';

    public function __construct(private readonly PostgresService $pg) {}

    public static function connectionName(Binding|string $binding): string
    {
        return 'runr-'.strtolower($binding instanceof Binding ? (string) $binding->id : $binding);
    }

    public static function roleName(BindingAttachment $attachment): string
    {
        return self::connectionName((string) $attachment->binding_id).'-'.strtolower((string) $attachment->project_id);
    }

    public static function secretName(BindingAttachment $attachment, Project $project): string
    {
        return mb_substr($project->slug.'-pgdyn-'.substr(strtolower((string) $attachment->id), -10), 0, 253);
    }

    public static function generatorName(BindingAttachment $attachment): string
    {
        return 'runr-pgdyn-'.strtolower((string) $attachment->id);
    }

    /** Postgres user the OpenBao connection of a binding works as. */
    public static function adminRole(Binding $binding): string
    {
        return 'runr_bao_'.substr(strtolower((string) $binding->id), -12);
    }

    /** @var array<string, bool> */
    private static array $columns = [];

    /** binding_attachments has credential_mode on the current connection (migrated: 2026_10_21_210002 / 0079). */
    public static function supported(): bool
    {
        if (! BindingEnv::tablesReady()) {
            return false;
        }
        $connection = (new BindingAttachment)->getConnection();
        $key = $connection->getName().':'.$connection->getDatabaseName();

        return self::$columns[$key] ??= Schema::connection($connection->getName())->hasColumn('binding_attachments', 'credential_mode');
    }

    /**
     * The project's dynamic attachments.
     *
     * @return Collection<int, BindingAttachment>
     */
    public static function attachmentsOf(Project $project): Collection
    {
        if ($project->getKey() === null || ! self::supported()) {
            return collect();
        }

        return BindingAttachment::query()
            ->where('project_id', $project->getKey())
            ->where('credential_mode', self::MODE_DYNAMIC)
            ->with('binding')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * OpenBao roles whose credentials the project may read (its policy, OpenBaoAccess::ensureProject()).
     *
     * @return list<string>
     */
    public static function rolesOf(Project $project): array
    {
        return self::attachmentsOf($project)->map(fn (BindingAttachment $a) => self::roleName($a))->values()->all();
    }

    /**
     * Why the attachment cannot get dynamic credentials (null: it can).
     */
    public static function refusal(Binding $binding, Project $project): ?string
    {
        if ($binding->type !== 'postgres') {
            return __('api.bindings.dynamic_postgres_only');
        }
        // OpenBao's database connection and the users it hands out live in one region
        // (docs/global-projects.md, "Data")
        if ($project->isGlobal()) {
            return __('api.global.dynamic_credentials_refused');
        }
        // OpenBao's connection is made with the binding's database and role: they must exist
        if ($binding->status !== Binding::STATUS_READY || empty($binding->credentials)) {
            return __('api.bindings.dynamic_not_ready', ['name' => $binding->name]);
        }
        $region = $project->knativeRegion;
        $settings = $region ? RegionSecretSetting::forRegion($region) : null;
        if (! $settings?->openbaoReady() || ! $settings->esoReady()) {
            return __('api.bindings.dynamic_needs_openbao', ['region' => $region->name ?? '—']);
        }

        return null;
    }

    /**
     * OpenBao's side of a dynamic attachment: the binding's connection (once), the attachment's
     * role and the project's policy. The region's objects follow with the bindings sync.
     *
     * @throws BindingException
     */
    public function enable(BindingAttachment $attachment): void
    {
        $binding = $attachment->binding;
        $project = $attachment->project;
        if ($reason = self::refusal($binding, $project)) {
            throw new BindingException($reason);
        }

        try {
            // OpenBao reaches the binding's Postgres in the data namespace (its base policies:
            // regions whose data services predate the OpenBao policy get it here)
            if ($binding->region) {
                app(BindingsCluster::class)->ensureDataNamespace($binding->region);
            }
            $client = app(OpenBaoClients::class)->forRegion($project->knativeRegion);
            $this->ensureConnection($client, $binding);

            $owner = (string) $binding->credential('username');
            $client->write('database/roles/'.self::roleName($attachment), [
                'db_name' => self::connectionName($binding),
                'creation_statements' => [
                    'CREATE ROLE "{{name}}" WITH LOGIN PASSWORD \'{{password}}\' VALID UNTIL \'{{expiration}}\' IN ROLE '.PostgresSql::ident($owner).';',
                    // Objects the user creates belong to the binding's role, like the static user's
                    'ALTER ROLE "{{name}}" SET role = '.PostgresSql::ident($owner).';',
                ],
                'revocation_statements' => [
                    'REASSIGN OWNED BY "{{name}}" TO '.PostgresSql::ident($owner).';',
                    'DROP OWNED BY "{{name}}";',
                    'DROP ROLE IF EXISTS "{{name}}";',
                ],
                'default_ttl' => (string) config('secrets.openbao.db_default_ttl', '168h'),
                'max_ttl' => (string) config('secrets.openbao.db_max_ttl', '720h'),
            ]);

            $roles = array_values(array_unique(array_merge(self::rolesOf($project), [self::roleName($attachment)])));
            OpenBaoAccess::ensureProject($client, SecretScope::forProject($project), $roles);
        } catch (BindingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BindingException(__('api.bindings.dynamic_failed', ['error' => mb_substr($e->getMessage(), 0, 300)]), 0, $e);
        }
    }

    /**
     * The attachment no longer gets dynamic credentials (detached, or back to static): its role
     * goes, the users OpenBao issued for it are revoked (dropped in Postgres), the project's
     * policy loses it. Best effort: a region that does not answer is logged.
     */
    public function disable(BindingAttachment $attachment, ?Project $project = null): void
    {
        $project ??= $attachment->project;
        $region = $project->knativeRegion;
        if (! $region || ! RegionSecretSetting::forRegion($region)->openbaoReady()) {
            return;
        }

        try {
            $client = app(OpenBaoClients::class)->forRegion($region);
            $role = self::roleName($attachment);
            $client->write('sys/leases/revoke-prefix/database/creds/'.$role);
            $client->delete('database/roles/'.$role);
            $roles = array_values(array_diff(self::rolesOf($project), [$role]));
            OpenBaoAccess::ensureProject($client, SecretScope::forProject($project), $roles);
        } catch (\Throwable $e) {
            Log::warning('Dynamic credentials of an attachment not removed from OpenBao', [
                'attachment' => $attachment->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** The binding goes: its connection in OpenBao too (its users with it). */
    public function forgetBinding(Binding $binding): void
    {
        $region = $binding->region;
        if (! $region || ! $region->kubeconfig || ! RegionSecretSetting::forRegion($region)->openbaoReady()) {
            return;
        }

        try {
            $client = app(OpenBaoClients::class)->forRegion($region);
            $client->write('sys/leases/revoke-prefix/database/creds/'.self::connectionName($binding));
            $client->delete('database/config/'.self::connectionName($binding));
        } catch (\Throwable $e) {
            Log::warning('OpenBao connection of a deleted binding not removed', ['binding' => $binding->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The objects the region needs for a project's dynamic attachments: its service account, and
     * per attachment the generator and the ExternalSecret. Empty without dynamic attachments.
     *
     * @return list<array>
     */
    public static function manifests(Project $project, string $namespace): array
    {
        $attachments = self::attachmentsOf($project);
        if ($attachments->isEmpty() || ! $project->knativeRegion) {
            return [];
        }

        $scope = SecretScope::forProject($project);
        $settings = RegionSecretSetting::forRegion($project->knativeRegion);
        $provider = OpenBaoAccess::vaultProvider($scope, $settings);
        unset($provider['path'], $provider['version']);
        $labels = $scope->labels([ExternalSecretManifests::LABEL => 'pgdyn']);

        $manifests = [OpenBaoAccess::workloadManifests($scope, $settings)[0]];
        foreach ($attachments as $attachment) {
            $binding = $attachment->binding;
            if (empty($binding->credentials ?? null)) {
                continue;
            }
            $prefix = (string) $attachment->env_prefix;
            $url = sprintf(
                'postgresql://{{ .username | urlquery }}:{{ .password | urlquery }}@%s:%d/%s?sslmode=require',
                (string) $binding->credential('host'),
                (int) $binding->credential('port', BindingManifests::PG_PORT),
                rawurlencode((string) $binding->credential('database')),
            );

            $manifests[] = [
                'apiVersion' => self::GENERATOR_API,
                'kind' => 'VaultDynamicSecret',
                'metadata' => ['name' => self::generatorName($attachment), 'namespace' => $namespace, 'labels' => $labels],
                'spec' => [
                    'path' => '/database/creds/'.self::roleName($attachment),
                    'method' => 'GET',
                    'resultType' => 'Data',
                    'provider' => $provider,
                ],
            ];
            $manifests[] = [
                'apiVersion' => ExternalSecretManifests::API_VERSION,
                'kind' => 'ExternalSecret',
                'metadata' => ['name' => self::secretName($attachment, $project), 'namespace' => $namespace, 'labels' => $labels],
                'spec' => [
                    'refreshInterval' => (string) config('secrets.openbao.db_refresh', '24h'),
                    'target' => [
                        'name' => self::secretName($attachment, $project),
                        'creationPolicy' => 'Owner',
                        'template' => [
                            'engineVersion' => 'v2',
                            'metadata' => ['labels' => $labels],
                            'data' => [
                                $prefix.'PGUSER' => '{{ .username }}',
                                $prefix.'PGPASSWORD' => '{{ .password }}',
                                $prefix.'DATABASE_URL' => $url,
                            ],
                        ],
                    ],
                    'dataFrom' => [['sourceRef' => ['generatorRef' => [
                        'apiVersion' => self::GENERATOR_API,
                        'kind' => 'VaultDynamicSecret',
                        'name' => self::generatorName($attachment),
                    ]]]],
                ],
            ];
        }

        return $manifests;
    }

    /**
     * The Secrets the workload reads for the project's dynamic attachments (envFrom, after the
     * static bindings Secret so their keys win).
     *
     * @return list<string>
     */
    public static function secretNames(Project $project): array
    {
        return self::attachmentsOf($project)
            ->map(fn (BindingAttachment $a) => self::secretName($a, $project))
            ->values()->all();
    }

    /**
     * A renewal reaches the running workload: when an ExternalSecret of the project's dynamic
     * credentials synced a new user since the workload's revision was made, the Knative Service's
     * template gets the renewals' fingerprint as an annotation — a new revision, pods on the new
     * user. CronJob runs read the Secret anew each time. Called by `secrets:sync-status`.
     *
     * @return int how many workloads were rolled
     */
    public function restartOnRenewal(Project $project, KubernetesClient $k8s, string $namespace): int
    {
        $items = $k8s->listResourcesOrFail(ExternalSecretManifests::API_VERSION, 'ExternalSecret', $namespace, [
            ExternalSecretManifests::LABEL => 'pgdyn',
            'runr/project-id' => (string) $project->id,
        ]);
        $versions = [];
        foreach ($items as $item) {
            $versions[(string) ($item['metadata']['name'] ?? '')] = (string) ($item['status']['syncedResourceVersion'] ?? '');
        }
        ksort($versions);
        if (array_filter($versions) === []) {
            return 0;
        }
        $fingerprint = substr(hash('sha256', json_encode($versions)), 0, 16);

        $service = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $project->slug, $namespace);
        $current = $service['spec']['template']['metadata']['annotations'][self::RENEWAL_ANNOTATION] ?? null;
        if (! is_array($service) || $current === $fingerprint) {
            return 0;
        }

        $patched = $k8s->mergePatch('serving.knative.dev/v1', 'Service', $project->slug, $namespace, [
            'spec' => ['template' => ['metadata' => ['annotations' => [self::RENEWAL_ANNOTATION => $fingerprint]]]],
        ]);

        return $patched ? 1 : 0;
    }

    /**
     * restartOnRenewal() for every project with dynamic attachments in the current database
     * context (`secrets:sync-status`).
     *
     * @return list<string> regions that did not answer
     */
    public function renewAll(): array
    {
        if (! self::supported()) {
            return [];
        }

        $projects = Project::query()
            ->whereIn('id', BindingAttachment::query()->where('credential_mode', self::MODE_DYNAMIC)->select('project_id'))
            ->with('knativeRegion')
            ->get();

        $failed = [];
        foreach ($projects->groupBy('knative_region_id') as $group) {
            $region = $group->first()->knativeRegion;
            if (! $region || ! $region->kubeconfig || $region->is_demo) {
                continue;
            }
            try {
                $k8s = new KubernetesClient($region->kubeconfig);
                $namespace = (string) ($region->project_namespace ?? $region->name);
                foreach ($group as $project) {
                    $this->restartOnRenewal($project, $k8s, $namespace);
                }
            } catch (\Throwable $e) {
                $failed[] = "{$region->name}: {$e->getMessage()}";
            }
        }

        return $failed;
    }

    /**
     * The binding's connection in OpenBao, once: a Postgres role for OpenBao that may create
     * members of the binding's role, handed over with a password OpenBao replaces right away.
     */
    private function ensureConnection(OpenBaoClient $client, Binding $binding): void
    {
        if ($client->read('database/config/'.self::connectionName($binding)) !== null) {
            return;
        }

        $admin = self::adminRole($binding);
        $owner = (string) $binding->credential('username');
        $password = Str::random(40);
        $sql = 'DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '.PostgresSql::literal($admin).') THEN '
            .'CREATE ROLE '.PostgresSql::ident($admin).' LOGIN CREATEROLE PASSWORD '.PostgresSql::literal($password).'; '
            .'ELSE ALTER ROLE '.PostgresSql::ident($admin).' LOGIN CREATEROLE PASSWORD '.PostgresSql::literal($password).'; END IF; END $$;'."\n"
            .'GRANT '.PostgresSql::ident($owner).' TO '.PostgresSql::ident($admin).' WITH ADMIN OPTION;';

        $cluster = $binding->isDedicated() ? $binding->dedicatedClusterName() : BindingManifests::PG_SHARED;
        $result = $this->pg->sql($binding->region, $cluster, 'postgres-openbao', $sql);
        unset($sql);
        if (! $result->ok) {
            throw new BindingException(__('api.bindings.postgres_sql_failed', ['error' => $result->tail()]));
        }

        $client->write('database/config/'.self::connectionName($binding), [
            'plugin_name' => 'postgresql-database-plugin',
            'connection_url' => sprintf(
                'postgresql://{{username}}:{{password}}@%s:%d/%s?sslmode=require',
                (string) $binding->credential('host'),
                (int) $binding->credential('port', BindingManifests::PG_PORT),
                rawurlencode((string) $binding->credential('database')),
            ),
            'username' => $admin,
            'password' => $password,
            'allowed_roles' => [self::connectionName($binding).'-*'],
            'verify_connection' => true,
        ]);
        unset($password);

        // From here on only OpenBao knows the password
        $client->write('database/rotate-root/'.self::connectionName($binding));
    }
}
