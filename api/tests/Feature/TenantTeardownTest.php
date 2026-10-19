<?php

namespace Tests\Feature;

use App\Mcp\Models\McpSession;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Models\Version;
use App\Services\KnativeDeployService;
use App\Support\DeployGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Tenancy\Http\Controllers\TenantController;
use Modules\Tenancy\Http\Middleware\IdentifyTenant;
use Modules\Tenancy\Jobs\DeleteTenantJob;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Support\TenantCentralTables;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * + review: deleting a tenant runs in DeleteTenantJob (the HTTP call answers 202).
 * Every project is torn down in its region — a synced platform region through the central
 * kubeconfig — the tenant's config-domain entries and anything still labelled runr/tenant=<slug>
 * are removed, the runr-agent of tenant-owned regions is uninstalled, and only then are the
 * database, storage/git/<slug> and the row dropped. A failed teardown keeps the tenant as
 * delete_failed (unless forced).
 */
class TenantTeardownTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    /** @var list<string> "METHOD host/path" */
    private array $calls = [];

    /** @var array<string, array> DELETE bodies by "host/path" */
    private array $deleteBodies = [];

    /** @var list<string> "host/path" answered with 500 on DELETE */
    private array $failDeletes = [];

    /** @var array<string, array> config-domain data by host */
    private array $configDomain = [];

    /** @var array<string, list<array>> list items by "host/path?labelSelector" */
    private array $lists = [];

    protected function setUp(): void
    {
        parent::setUp();

        // This suite may run with TENANCY_ENABLED off, which skips TenancyServiceProvider's
        // event wiring: WiresTenancy registers what $tenant->run() needs to switch connections,
        // the central connection and the Tenancy module's central migrations
        $this->setUpTenancy();
    }

    protected function tearDown(): void
    {
        DeployGate::setResolver(null);
        $this->tearDownTenancy();

        parent::tearDown();
    }

    private function kubeconfig(string $host): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => "https://{$host}:6443", 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function fakeCluster(): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            $key = parse_url($url, PHP_URL_HOST).parse_url($url, PHP_URL_PATH);
            $query = urldecode((string) parse_url($url, PHP_URL_QUERY));
            $method = $request->method();
            $this->calls[] = "{$method} {$key}";

            if ($method === 'DELETE') {
                $this->deleteBodies[$key] = $request->data();

                return in_array($key, $this->failDeletes, true)
                    ? Http::response(['kind' => 'Status', 'code' => 500], 500)
                    : Http::response(['kind' => 'Status'], 200);
            }

            if (str_ends_with($key, '/configmaps/config-domain')) {
                $host = (string) parse_url($url, PHP_URL_HOST);
                if ($method === 'PATCH') {
                    $this->configDomain[$host] = json_decode($request->body(), true)['data'] ?? [];

                    return Http::response([], 200);
                }

                return isset($this->configDomain[$host])
                    ? Http::response(['data' => $this->configDomain[$host]])
                    : Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            if ($method === 'GET' && str_starts_with($query, 'labelSelector=')) {
                return Http::response(['items' => $this->lists[$key.'?'.substr($query, strlen('labelSelector='))] ?? []]);
            }

            return Http::response(['kind' => 'Status', 'code' => 404], 404);
        });
    }

    private function deletes(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => str_starts_with($c, 'DELETE ')));
    }

    /**
     * The tenant migrations these tests need (projects, knative_regions, settings) — the whole
     * TenantMigrations folder pulls in PostgreSQL-only migrations that don't run on sqlite.
     */
    private function migrateTenantSchema(): void
    {
        foreach ([
            'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
            'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
            'Modules/Tenancy/Database/TenantMigrations/0004_create_settings_table.php',
            // KnativeRegion's attribute defaults (prometheus_managed / loki_managed) are inserted
            'Modules/Tenancy/Database/TenantMigrations/0023_add_observability_managed_flags_to_knative_regions.php',
            // Project cleanup also removes custom domains and private database connections
            'Modules/Tenancy/Database/TenantMigrations/0028_create_project_domains_table.php',
            'Modules/Tenancy/Database/TenantMigrations/0034_create_private_db_bindings_table.php',
        ] as $migration) {
            (require base_path($migration))->up();
        }
    }

    /**
     * Tenant "acme" with an own region (fn @ tenant.test) and, optionally, a synced copy of a
     * platform region (platform @ platform.test, kubeconfig only in central).
     *
     * @param  list<array{0: string, 1: string}>  $projects  [name, 'own'|'platform']
     */
    private function tenant(array $projects, bool $withPlatformRegion = false): Tenant
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $tenant->database()->manager()->createDatabase($tenant);
        // tearDownTenancy() drops the database of a tenant the test does not delete (on SQLite a
        // file in database/ of the checkout, on PostgreSQL a schema)
        $this->wiredTenants[] = $tenant;

        $platform = null;
        if ($withPlatformRegion) {
            $platform = KnativeRegion::create([
                'name' => 'platform', 'project_namespace' => 'shared', 'kubeconfig' => $this->kubeconfig('platform.test'), 'is_active' => true,
            ]);
            $tenant->regions()->attach($platform->id, ['cpu_quota_m' => 1000, 'memory_quota_mb' => 1024]);
        }

        $tenant->run(function () use ($projects, $platform) {
            $this->migrateTenantSchema();

            $own = new KnativeRegion(['name' => 'fn', 'project_namespace' => 'fn', 'kubeconfig' => $this->kubeconfig('tenant.test'), 'is_active' => true]);
            $own->forceFill(['is_synced' => false])->save();

            $regionIds = ['own' => $own->id];

            if ($platform) {
                // Synced copy: same id, no kubeconfig of its own
                $copy = new KnativeRegion(['name' => 'platform', 'project_namespace' => 'shared', 'is_active' => true]);
                $copy->forceFill(['id' => $platform->id, 'is_synced' => true])->save();
                $regionIds['platform'] = $copy->id;
            }

            foreach ($projects as [$name, $where]) {
                $project = Project::create(['name' => $name, 'service_type' => 'caas']);
                $project->forceFill(['knative_region_id' => $regionIds[$where]])->saveQuietly();
            }
        });

        return $tenant;
    }

    private function tenantDatabaseExists(Tenant $tenant): bool
    {
        return $tenant->database()->manager()->databaseExists($tenant->database()->getName());
    }

    private function destroy(Tenant $tenant, array $input = []): JsonResponse
    {
        $request = Request::create("/api/tenants/{$tenant->id}", 'DELETE', $input);
        $request->setUserResolver(fn () => null);

        return app(TenantController::class)->destroy($request, $tenant->id);
    }

    /**
     * A member's API tokens created in the tenant and in another tenant, and their sign-in session
     * (no tenant), with an MCP session in the tenant.
     *
     * @return array{0: User, 1: array<string, int>} the member and their token ids by name
     */
    private function memberTokens(Tenant $tenant): array
    {
        $user = User::factory()->create(['username' => 'member']);
        $other = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);
        $ids = [];
        foreach ([$tenant->id => 'ci-acme', $other->id => 'ci-globex', '' => 'api'] as $tenantId => $name) {
            $token = $user->createToken($name)->accessToken;
            $token->forceFill(['tenant_id' => $tenantId ?: null])->save();
            $ids[$name] = (int) $token->getKey();
        }
        McpSession::query()->create([
            'id' => hash('sha256', 'member-acme'), 'token_id' => $ids['ci-acme'], 'user_id' => (string) $user->id,
            'tenant_id' => $tenant->id, 'protocol_version' => '2025-06-18', 'last_seen_at' => now(),
        ]);

        return [$user, $ids];
    }

    /** @return list<int> */
    private function tokenIds(User $user): array
    {
        return PersonalAccessToken::query()->where('tokenable_id', $user->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_delete_answers_202_and_the_job_tears_everything_down(): void
    {
        $tenant = $this->tenant([['proj-a', 'own'], ['proj-b', 'own']]);
        [$member, $tokens] = $this->memberTokens($tenant);
        File::ensureDirectoryExists(storage_path('git/acme/some-project'));
        File::put(storage_path('git/acme/some-project/main.py'), 'print(1)');
        $this->fakeCluster();

        $response = $this->destroy($tenant);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertContains('DELETE tenant.test/apis/serving.knative.dev/v1/namespaces/fn/services/proj-a', $this->deletes());
        $this->assertContains('DELETE tenant.test/apis/serving.knative.dev/v1/namespaces/fn/services/proj-b', $this->deletes());
        $this->assertNull(Tenant::find($tenant->id), 'tenant row is gone once teardown succeeded');
        $this->assertFalse($this->tenantDatabaseExists($tenant), 'tenant database dropped');
        $this->assertDirectoryDoesNotExist(storage_path('git/acme'));
        // The tokens created in the tenant went with it; the member's others stay
        $this->assertSame([$tokens['ci-globex'], $tokens['api']], $this->tokenIds($member));
        $this->assertSame(0, McpSession::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_a_failed_teardown_keeps_the_tenant_as_delete_failed_and_a_retry_completes_it(): void
    {
        $tenant = $this->tenant([['proj-broken', 'own'], ['proj-ok', 'own']]);
        [$member] = $this->memberTokens($tenant);
        $this->failDeletes = ['tenant.test/apis/serving.knative.dev/v1/namespaces/fn/services/proj-broken'];
        $this->fakeCluster();

        $this->destroy($tenant);

        // The others are still torn down
        $this->assertContains('DELETE tenant.test/apis/serving.knative.dev/v1/namespaces/fn/services/proj-ok', $this->deletes());

        $kept = Tenant::find($tenant->id);
        $this->assertNotNull($kept, 'tenant kept while something is still running');
        $this->assertCount(3, $this->tokenIds($member), 'the tokens of a kept tenant stay');
        $this->assertSame(Tenant::STATUS_DELETE_FAILED, $kept->status);
        $this->assertStringContainsString('proj-broken', $kept->status_message);
        $this->assertTrue($this->tenantDatabaseExists($tenant), 'database kept for the retry');

        // Retry (same endpoint) once the cluster answers again
        $this->failDeletes = [];
        $this->destroy($kept);

        $this->assertNull(Tenant::find($tenant->id));
    }

    public function test_force_deletes_the_tenant_despite_teardown_failures(): void
    {
        $tenant = $this->tenant([['proj-broken', 'own']]);
        $this->failDeletes = ['tenant.test/apis/serving.knative.dev/v1/namespaces/fn/services/proj-broken'];
        $this->fakeCluster();

        $this->destroy($tenant, ['force' => true]);

        $this->assertNull(Tenant::find($tenant->id));
        $this->assertFalse($this->tenantDatabaseExists($tenant));
    }

    public function test_projects_on_a_synced_platform_region_are_torn_down_through_the_central_kubeconfig(): void
    {
        $tenant = $this->tenant([['proj-p', 'platform']], withPlatformRegion: true);
        $this->fakeCluster();

        $this->destroy($tenant);

        $this->assertContains('DELETE platform.test/apis/serving.knative.dev/v1/namespaces/shared/services/proj-p', $this->deletes());
        $this->assertNull(Tenant::find($tenant->id));
    }

    public function test_the_tenants_config_domain_entries_are_removed_and_others_kept(): void
    {
        $tenant = $this->tenant([['proj-p', 'platform']], withPlatformRegion: true);
        $tenant->run(fn () => Setting::set('base_domain', 'acme.example'));

        $this->configDomain['platform.test'] = [
            'fn.runr.example' => '',
            'platform.acme.example' => "selector:\n  runr/tenant: acme",
            'platform.old-acme.dev' => "selector:\n  runr/tenant: acme",
            'platform.other.example' => "selector:\n  runr/tenant: other",
        ];
        $this->fakeCluster();

        $this->destroy($tenant);

        $this->assertSame([
            'fn.runr.example' => '',
            'platform.other.example' => "selector:\n  runr/tenant: other",
        ], $this->configDomain['platform.test']);
    }

    public function test_leftovers_labelled_for_the_tenant_are_swept_with_background_propagation(): void
    {
        $tenant = $this->tenant([['proj-a', 'own']]);
        $this->lists['tenant.test/apis/batch/v1/namespaces/fn/cronjobs?runr/tenant=acme'] = [['metadata' => ['name' => 'gone-project-acme-trigger']]];
        $this->lists['tenant.test/apis/apps/v1/namespaces/fn/deployments?runr/tenant=acme'] = [['metadata' => ['name' => 'gone-project-acme-qc']]];
        $this->fakeCluster();

        $this->destroy($tenant);

        $cron = 'tenant.test/apis/batch/v1/namespaces/fn/cronjobs/gone-project-acme-trigger';
        $this->assertContains("DELETE {$cron}", $this->deletes());
        $this->assertContains('DELETE tenant.test/apis/apps/v1/namespaces/fn/deployments/gone-project-acme-qc', $this->deletes());
        $this->assertSame('Background', $this->deleteBodies[$cron]['propagationPolicy'] ?? null);
    }

    public function test_the_agent_is_uninstalled_from_tenant_owned_regions_only(): void
    {
        $tenant = $this->tenant([['proj-a', 'own'], ['proj-p', 'platform']], withPlatformRegion: true);
        $this->fakeCluster();

        $this->destroy($tenant);

        $this->assertContains('DELETE tenant.test/apis/apps/v1/namespaces/observability/deployments/runr-agent', $this->deletes());
        $this->assertNotContains('DELETE platform.test/apis/apps/v1/namespaces/observability/deployments/runr-agent', $this->deletes());
    }

    public function test_a_running_deletion_is_not_started_twice(): void
    {
        $tenant = $this->tenant([]);
        $tenant->forceFill(['status' => Tenant::STATUS_DELETING])->save();

        $this->assertSame(409, $this->destroy($tenant)->getStatusCode());
        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_writes_to_a_tenant_being_deleted_answer_409_reads_still_work(): void
    {
        config(['tenancy.enabled' => true]);
        $tenant = $this->tenant([]);
        $tenant->forceFill(['status' => Tenant::STATUS_DELETING])->save();

        $user = User::factory()->create(['username' => 'member']);
        DB::connection('central')->table('tenant_users')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $middleware = new IdentifyTenant;
        $request = fn (string $method, string $uri) => tap(Request::create($uri, $method), fn ($r) => $r->setUserResolver(fn () => $user));

        try {
            $middleware->handle($request('POST', '/api/projects/x/deploy'), fn () => response('ok'));
            $this->fail('write accepted while the tenant is being deleted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame('ok', $middleware->handle($request('GET', '/api/projects'), fn () => response('ok'))->getContent());
        tenancy()->end();
        $this->assertSame('ok', $middleware->handle($request('POST', '/api/tenant/switch'), fn () => response('ok'))->getContent());
    }

    public function test_a_queued_deploy_does_not_start_while_the_gate_is_closed(): void
    {
        $region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn', 'kubeconfig' => $this->kubeconfig('tenant.test'), 'is_active' => true]);
        $project = Project::create(['name' => 'svc', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
        $this->fakeCluster();

        DeployGate::setResolver(fn () => 'Tenant acme is being deleted');
        app(KnativeDeployService::class)->deploy($project->fresh(), $version, $deployment);

        $this->assertSame('failed', $deployment->fresh()->status);
        $this->assertSame('Tenant acme is being deleted', $deployment->fresh()->knative_message);
        $this->assertSame([], array_filter($this->calls, fn ($c) => ! str_starts_with($c, 'GET ')));
    }

    public function test_the_job_is_unique_per_tenant(): void
    {
        $this->assertSame('t-1', (new DeleteTenantJob('t-1'))->uniqueId());
    }

    // ── Central tables (sandboxes, uploads, MCP, git, status pages, …) ─────────

    /**
     * A row of a central table with every required column filled, whatever the schema adds;
     * $values win. Returns the row's id (or '').
     *
     * @param  array<string, mixed>  $values
     */
    private function centralRow(string $table, array $values): string
    {
        $row = [];
        foreach (Schema::getColumns($table) as $column) {
            $name = $column['name'];
            if (array_key_exists($name, $values) || $column['nullable'] || $column['default'] !== null || $column['auto_increment']) {
                continue;
            }

            $type = strtolower((string) $column['type']);
            $length = preg_match('/\((\d+)\)/', $type, $m) ? (int) $m[1] : 20;
            $row[$name] = match (true) {
                str_contains($type, 'bool') || str_starts_with($type, 'tinyint') => false,
                str_contains($type, 'int') || str_contains($type, 'numeric') || str_contains($type, 'decimal')
                    || str_contains($type, 'double') || str_contains($type, 'float') || str_contains($type, 'real') => 1,
                str_contains($type, 'json') => '{}',
                str_contains($type, 'timestamp') || str_contains($type, 'datetime') => now(),
                str_contains($type, 'date') => now()->toDateString(),
                default => strtolower(Str::random(min($length, 26))),
            };
        }

        $row = $values + $row;
        DB::table($table)->insert($row);

        return (string) ($row['id'] ?? '');
    }

    /** Rows of $table whose $column is $value. */
    private function rows(string $table, string $column, string $value): int
    {
        return DB::table($table)->where($column, $value)->count();
    }

    /**
     * Tenant "acme" (own region fn @ tenant.test with an internal registry) with what it runs and
     * stores through central tables, next to rows of "globex", of the platform's regions and the
     * records that outlive an organization.
     *
     * @return array{0: Tenant, 1: Tenant, 2: string, 3: string} acme, globex, own region id, running sandbox id
     */
    private function tenantWithCentralRows(): array
    {
        config(['tenancy.enabled' => true]); // sandboxes resolve their region in the organization's context
        Storage::fake('public');

        $tenant = $this->tenant([]);
        $other = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);
        $ownId = $tenant->run(function () {
            $own = KnativeRegion::where('name', 'fn')->firstOrFail();
            $own->forceFill(['registry_mode' => 'internal'])->save();

            return (string) $own->id;
        });

        // Run or stored in the region: a sandbox pod, an upload receiver, an image manifest
        $running = $this->centralRow('sandboxes', [
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'knative_region_id' => $ownId, 'namespace' => 'runr-sbx-acme',
            'pod_name' => 'sbx-run', 'status' => 'running', 'env' => null,
        ]);
        $this->centralRow('sandbox_processes', ['id' => (string) Str::ulid(), 'sandbox_id' => $running]);
        $this->centralRow('sandbox_events', ['id' => (string) Str::ulid(), 'sandbox_id' => $running]);
        $this->centralRow('image_uploads', [
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'knative_region_id' => $ownId, 'status' => 'ready', 'receiver_name' => 'recv-acme',
        ]);
        $this->centralRow('uploaded_images', [
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'knative_region_id' => $ownId, 'registry_repository' => 'u/acme/app', 'digest' => 'sha256:abc',
        ]);
        // A custom runtime with its version and build
        $this->centralRow('runtimes', ['id' => 'acme-rt', 'source' => 'custom', 'tenant_id' => $tenant->id]);
        $this->centralRow('runtime_builds', ['id' => (string) Str::ulid(), 'runtime_id' => 'acme-rt', 'knative_region_id' => 'platform-region']);
        $this->centralRow('executor_images', ['runtime' => 'acme-rt']);
        // Branding override with an asset
        Storage::disk('public')->put('branding/acme/logo.png', 'png');
        $this->centralRow('brandings', ['tenant_id' => $tenant->id, 'logo_light_path' => 'branding/acme/logo.png']);

        // Every other table of the organization's rows: one of acme, one of globex
        foreach (TenantCentralTables::DELETED_WITH_TENANT as $table => $column) {
            if (! in_array($table, ['sandboxes', 'image_uploads', 'uploaded_images', 'runtimes', 'brandings'], true)) {
                $this->centralRow($table, [$column => $tenant->id]);
            }
            $this->centralRow($table, [$column => $other->id] + ($table === 'runtimes' ? ['id' => 'globex-rt'] : []));
        }

        // Settings of acme's own region go, the platform region's stay (also one configured from
        // inside acme); billing records stay
        $this->centralRow('sandbox_region_settings', ['knative_region_id' => $ownId]);
        $this->centralRow('sandbox_region_settings', ['knative_region_id' => 'platform-region']);
        $this->centralRow('region_tracing_configs', ['region_id' => $ownId, 'tenant_id' => $tenant->id]);
        $this->centralRow('region_tracing_configs', ['region_id' => 'platform-region', 'tenant_id' => $tenant->id]);
        $this->centralRow('sandbox_usage_records', ['tenant_id' => $tenant->id, 'knative_region_id' => $ownId]);

        return [$tenant, $other, $ownId, $running];
    }

    public function test_the_organizations_central_rows_and_what_runs_for_them_go_with_it(): void
    {
        [$tenant, $other, $ownId, $running] = $this->tenantWithCentralRows();
        $this->fakeCluster();

        $this->assertSame(202, $this->destroy($tenant)->getStatusCode());
        $this->assertNull(Tenant::find($tenant->id));

        // Sandbox pod, upload receiver and image manifest deleted in the region
        $this->assertContains('DELETE tenant.test/api/v1/namespaces/runr-sbx-acme/pods/sbx-run', $this->deletes());
        $this->assertContains('DELETE tenant.test/apis/batch/v1/namespaces/fn/jobs/recv-acme', $this->deletes());
        $this->assertNotEmpty(array_filter($this->deletes(), fn ($c) => str_ends_with($c, '/proxy/v2/u/acme/app/manifests/sha256:abc')));

        foreach (TenantCentralTables::DELETED_WITH_TENANT as $table => $column) {
            $this->assertSame(0, $this->rows($table, $column, $tenant->id), "{$table} of the deleted organization");
            $this->assertSame(1, $this->rows($table, $column, $other->id), "{$table} of another organization");
        }
        $this->assertSame(0, $this->rows('sandbox_processes', 'sandbox_id', $running));
        $this->assertSame(0, $this->rows('sandbox_events', 'sandbox_id', $running));
        $this->assertSame(0, $this->rows('runtime_builds', 'runtime_id', 'acme-rt'));
        $this->assertSame(0, $this->rows('executor_images', 'runtime', 'acme-rt'));
        $this->assertSame(0, $this->rows('sandbox_region_settings', 'knative_region_id', $ownId));
        $this->assertSame(1, $this->rows('sandbox_region_settings', 'knative_region_id', 'platform-region'));
        $this->assertSame(0, $this->rows('region_tracing_configs', 'region_id', $ownId));
        $this->assertSame(1, $this->rows('region_tracing_configs', 'region_id', 'platform-region'));
        $this->assertSame(1, $this->rows('sandbox_usage_records', 'tenant_id', $tenant->id), 'usage records outlive the organization');
        Storage::disk('public')->assertMissing('branding/acme/logo.png');
    }

    public function test_a_sandbox_pod_that_cannot_be_deleted_keeps_the_tenant_and_its_central_rows(): void
    {
        [$tenant] = $this->tenantWithCentralRows();
        $this->failDeletes = ['tenant.test/api/v1/namespaces/runr-sbx-acme/pods/sbx-run'];
        $this->fakeCluster();

        $this->destroy($tenant);

        $kept = Tenant::find($tenant->id);
        $this->assertSame(Tenant::STATUS_DELETE_FAILED, $kept?->status);
        $this->assertStringContainsString('sbx-run', (string) $kept->status_message);
        $this->assertSame(1, $this->rows('sandboxes', 'tenant_id', $tenant->id));
        $this->assertSame(1, $this->rows('mcp_servers', 'tenant_id', $tenant->id));

        // The retry finishes it once the cluster answers
        $this->failDeletes = [];
        $this->destroy($kept);

        $this->assertNull(Tenant::find($tenant->id));
        $this->assertSame(0, $this->rows('sandboxes', 'tenant_id', $tenant->id));
    }
}
