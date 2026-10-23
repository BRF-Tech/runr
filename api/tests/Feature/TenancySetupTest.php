<?php

namespace Tests\Feature;

use App\Events\UserRegistered;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\GitService;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Tenancy\Console\CreateTenantCommand;
use Modules\Tenancy\Console\MigrateTenantSchemasCommand;
use Modules\Tenancy\Console\TenancySetupCommand;
use Modules\Tenancy\Http\Controllers\TenantController;
use Modules\Tenancy\Listeners\CreateTenantOnRegistration;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Services\SingleTenantConversion;
use Modules\Tenancy\Services\TenantAwareGitService;
use Modules\Tenancy\Services\TenantSchema;
use Modules\Ticket\Models\SupportTicket;
use SharedSupport\TenantMigrationRegistry;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * php artisan tenancy:setup turns a single-organization installation into the first tenant of a
 * multi-tenant one: every tenant table's rows, memberships with the organization roles, the
 * owners and admins as platform administrators, regions, organization settings, the central rows
 * and API tokens of the organization, its git storage. Resumable, never run twice, a dry run that
 * changes nothing. Also: every path that creates or migrates a tenant runs every module's tenant
 * migrations (TenantSchema), and tenancy:migrate --fresh empties the tenant's schema only.
 * Runs on SQLite and on PostgreSQL (DB_CONNECTION=pgsql: schema per tenant next to "public").
 */
class TenancySetupTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private const REGISTRY_FIXTURE = 'tests/Fixtures/tenant-migrations';

    /** @var list<string> */
    private array $registryBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();

        // Ticket module's central tables (its tenant tables come with Tenancy's tenant migrations)
        Artisan::call('migrate', ['--path' => 'Modules/Ticket/Database/Migrations', '--force' => true]);

        $this->registryBefore = TenantMigrationRegistry::paths();
        TenantMigrationRegistry::register(base_path('Modules/Ticket/Database/TenantMigrations'));

        config(['tenancy.enabled' => true]);
        $kernel = $this->app->make(Kernel::class);
        foreach ([TenancySetupCommand::class, MigrateTenantSchemasCommand::class, CreateTenantCommand::class] as $command) {
            $kernel->registerCommand($this->app->make($command));
        }
    }

    protected function tearDown(): void
    {
        foreach (Tenant::query()->get() as $tenant) {
            $this->wiredTenants[] = $tenant;
        }
        $registry = new \ReflectionProperty(TenantMigrationRegistry::class, 'paths');
        $registry->setValue(null, $this->registryBefore);

        $this->tearDownTenancy();
        parent::tearDown();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * A single-organization installation: four accounts (one without a role assignment), a custom
     * role, a group with an organization role and project access, a region, a project with a
     * version, a deployment and a support ticket, settings, a status page lookup, an API token and
     * a sign-in session, the internal git repository and a project remote.
     *
     * @return array<string, mixed>
     */
    private function singleOrganization(): array
    {
        config(['tenancy.enabled' => false]);   // the users join the single organization as they would

        $org = new SingleTenantOrganization;
        $owner = User::factory()->create(['username' => 'olivia']);
        $admin = User::factory()->create(['username' => 'adam']);
        $dev = User::factory()->create(['username' => 'dora']);
        $plain = User::factory()->create(['username' => 'paul']);
        $org->setMemberRole($owner->id, 'owner');
        $org->setMemberRole($admin->id, 'admin');
        $org->setMemberRole($dev->id, 'support');
        RoleAssignment::query()->where('subject_id', (string) $plain->id)->delete();

        DB::table('rbac_roles')->insert([
            'id' => (string) Str::ulid(), 'key' => 'support', 'name' => 'Support', 'description' => null,
            'permissions' => json_encode(['projects.view', 'tickets.view']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $groupId = (string) Str::ulid();
        DB::table('rbac_groups')->insert(['id' => $groupId, 'name' => 'Oncall', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('rbac_group_user')->insert(['group_id' => $groupId, 'user_id' => $plain->id, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

        $region = KnativeRegion::create([
            'name' => 'fra', 'project_namespace' => 'fra', 'kubeconfig' => 'apiVersion: v1', 'is_active' => true,
            'total_cpu_m' => 8000, 'total_memory_mb' => 16384,
        ]);

        $project = Project::create(['name' => 'shop', 'service_type' => 'caas', 'created_by' => (string) $owner->id]);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        DB::table('versions')->insert(['id' => (string) Str::ulid(), 'project_id' => $project->id, 'number' => 1, 'created_by' => 'olivia', 'created_at' => now(), 'updated_at' => now()]);
        $versionId = (string) DB::table('versions')->value('id');
        DB::table('deployments')->insert(['id' => (string) Str::ulid(), 'project_id' => $project->id, 'version_id' => $versionId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('container_configs')->insert([
            'id' => (string) Str::ulid(), 'project_id' => $project->id, 'image' => 'nginx:1.27', 'trigger_type' => 'queue',
            'queue_stream' => 'orders', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A CaaS project on an image uploaded as the platform (single-organization uploads)
        $web = Project::create(['name' => 'web', 'service_type' => 'caas', 'created_by' => (string) $owner->id]);
        DB::table('container_configs')->insert([
            'id' => (string) Str::ulid(), 'project_id' => $web->id, 'image' => 'registry.fra.test/runr-uploads/platform/web:1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('rbac_role_assignments')->insert([
            ['id' => (string) Str::ulid(), 'subject_type' => 'group', 'subject_id' => $groupId, 'role' => 'viewer', 'project_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::ulid(), 'subject_type' => 'user', 'subject_id' => (string) $plain->id, 'role' => 'developer', 'project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $ticket = new SupportTicket(['user_id' => $dev->id, 'subject' => 'Help', 'description' => 'Please', 'category' => 'general', 'priority' => 'low']);
        $ticket->forceFill(['id' => 7])->save();

        Setting::set('rbac_default_role', 'viewer');
        Setting::set('base_domain', 'fn.example.test');

        DB::table('status_page_routes')->insert([
            'id' => (string) Str::ulid(), 'slug' => 'shop-status', 'tenant_id' => null, 'status_page_id' => (string) Str::ulid(),
            'is_public' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ci = $owner->createToken('ci')->accessToken;
        $session = $owner->createToken('api')->accessToken;

        $sha = app(GitService::class)->writeFiles($project, ['main.py' => 'print("shop")'], 'shop: v1');
        File::ensureDirectoryExists(storage_path("git-remotes/_/{$project->id}.git"));
        File::put(storage_path("git-remotes/_/{$project->id}.git/HEAD"), "ref: refs/heads/main\n");

        config(['tenancy.enabled' => true]);

        return compact('owner', 'admin', 'dev', 'plain', 'region', 'project', 'ticket', 'ci', 'session', 'sha', 'groupId');
    }

    /** @return mixed $callback's result in the tenant's context */
    private function inTenant(Tenant $tenant, \Closure $callback): mixed
    {
        return TenantSchema::inTenant($tenant, $callback);
    }

    private function tenantTable(Tenant $tenant, string $table): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? $tenant->database()->getName().'.'.$table : $table;
    }

    private function rows(Tenant $tenant, string $table, ?\Closure $where = null): int
    {
        return $this->inTenant($tenant, fn () => DB::table($this->tenantTable($tenant, $table))->when($where, $where)->count());
    }

    // ── Conversion ──────────────────────────────────────────────────────────

    public function test_converts_a_single_organization_installation_into_its_first_tenant(): void
    {
        $f = $this->singleOrganization();

        $this->artisan('tenancy:setup', ['--force' => true])
            ->expectsOutputToContain('Converted: the installation is organization Default (runr).')
            ->expectsOutputToContain('shop: orders → orders-runr')
            ->expectsOutputToContain('web: registry.fra.test/runr-uploads/platform/web:1')
            ->assertSuccessful();

        $tenant = Tenant::query()->sole();
        $this->assertSame('runr', $tenant->slug, 'default slug = the git remote owner: existing remotes keep working');
        $this->assertSame('Default', $tenant->name);

        // Memberships carry the organization roles; the account without one gets the default role
        $roles = TenantUser::query()->where('tenant_id', $tenant->id)->pluck('role', 'user_id')->all();
        $this->assertSame(['owner', 'admin', 'support', 'viewer'], [$roles[$f['owner']->id], $roles[$f['admin']->id], $roles[$f['dev']->id], $roles[$f['plain']->id]]);

        // Owners and admins are platform administrators now, nobody else
        $this->assertSame(['adam', 'olivia'], User::query()->where('is_admin', true)->orderBy('username')->pluck('username')->all());

        // Rows of the tenant tables, in their schema
        $this->assertSame(2, $this->rows($tenant, 'projects'));
        $this->assertSame(1, $this->rows($tenant, 'versions'));
        $this->assertSame(1, $this->rows($tenant, 'deployments'));
        $this->assertSame(1, $this->rows($tenant, 'support_tickets'));
        $this->assertSame(1, $this->rows($tenant, 'rbac_roles'));
        $this->assertSame(1, $this->rows($tenant, 'rbac_groups'));
        $this->assertSame(1, $this->rows($tenant, 'rbac_group_user'));
        // Group role and project access come along; organization roles of accounts are the memberships
        $this->assertSame(2, $this->rows($tenant, 'rbac_role_assignments'));
        $this->assertSame(0, $this->rows($tenant, 'rbac_role_assignments', fn ($q) => $q->where('subject_type', 'user')->whereNull('project_id')));
        $this->assertSame(4, $this->rows($tenant, 'users'));

        // The project keeps its id, slug and region; the region is a synced copy, assigned with its capacity
        $copy = $this->inTenant($tenant, fn () => DB::table($this->tenantTable($tenant, 'projects'))->where('name', 'shop')->first());
        $this->assertSame([$f['project']->id, 'shop', $f['region']->id], [$copy->id, $copy->slug, $copy->knative_region_id]);
        $this->assertTrue((bool) $this->inTenant($tenant, fn () => DB::table($this->tenantTable($tenant, 'knative_regions'))->where('id', $f['region']->id)->value('is_synced')));
        $this->assertNull($this->inTenant($tenant, fn () => DB::table($this->tenantTable($tenant, 'knative_regions'))->where('id', $f['region']->id)->value('kubeconfig')));
        $pivot = DB::table('tenant_regions')->where('tenant_id', $tenant->id)->first();
        $this->assertSame([8000, 16384], [(int) $pivot->cpu_quota_m, (int) $pivot->memory_quota_mb]);

        // Organization settings move; platform settings stay central (and reach the tenant as fallback)
        $settings = $this->inTenant($tenant, fn () => DB::table($this->tenantTable($tenant, 'settings'))->pluck('value', 'key')->all());
        $this->assertSame(['rbac_default_role' => 'viewer'], $settings);

        // Central rows and API tokens of the organization; sign-in sessions stay unbound
        $this->assertSame($tenant->id, DB::table('status_page_routes')->value('tenant_id'));
        $this->assertSame($tenant->id, DB::table('personal_access_tokens')->where('id', $f['ci']->id)->value('tenant_id'));
        $this->assertNull(DB::table('personal_access_tokens')->where('id', $f['session']->id)->value('tenant_id'));

        // Nothing is deleted from the central schema
        $this->assertSame(2, DB::table('projects')->count());

        // Git: the internal repository with its history, and the project remotes
        $this->assertDirectoryDoesNotExist(storage_path('git/.git'));
        $this->assertDirectoryExists(storage_path('git/runr/.git'));
        $this->assertFileExists(storage_path("git-remotes/runr/{$f['project']->id}.git/HEAD"));
        $this->assertDirectoryDoesNotExist(storage_path('git-remotes/_'));
        $git = new TenantAwareGitService;
        $file = $this->inTenant($tenant, fn () => $git->getFileAtVersion($f['project'], $f['sha'], 'main.py'));
        $this->assertSame('print("shop")', $file);

        // The next tenancy:migrate (every upgrade) finds nothing to run
        $this->assertStringContainsString('Nothing to migrate', TenantSchema::migrate($tenant));

        // Tenant migration 0019 dropped the user FKs of the support tables in the tenant's own
        // schema (staff accounts that reply are central): on PostgreSQL and SQLite alike
        $userKeys = $this->inTenant($tenant, fn () => array_filter(
            Schema::getForeignKeys($this->tenantTable($tenant, 'support_ticket_replies')),
            fn (array $key) => $key['columns'] === ['user_id'],
        ));
        $this->assertSame([], $userKeys);

        $state = (new SingleTenantConversion)->state();
        $this->assertSame($tenant->id, $state['tenant_id']);
        $this->assertArrayHasKey('completed_at', $state);
    }

    public function test_a_converted_tenant_continues_the_id_sequences(): void
    {
        $this->singleOrganization();
        $this->artisan('tenancy:setup', ['--force' => true])->assertSuccessful();
        $tenant = Tenant::query()->sole();

        $id = $this->inTenant($tenant, fn () => SupportTicket::query()->create([
            'user_id' => 1, 'subject' => 'Next', 'description' => 'One more', 'category' => 'general', 'priority' => 'low',
        ])->id);

        $this->assertSame(8, (int) $id);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $f = $this->singleOrganization();
        $this->artisan('tenancy:setup', ['--force' => true])->assertSuccessful();
        $tenant = Tenant::query()->sole();

        // The organization removes a member and a project after the conversion
        TenantUser::query()->where('tenant_id', $tenant->id)->where('user_id', $f['plain']->id)->delete();
        $this->inTenant($tenant, fn () => DB::table($this->tenantTable($tenant, 'projects'))->delete());
        DB::table('status_page_routes')->insert([
            'id' => (string) Str::ulid(), 'slug' => 'platform', 'tenant_id' => null, 'status_page_id' => (string) Str::ulid(),
            'is_public' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('tenancy:setup', ['--force' => true])
            ->expectsOutputToContain('was converted')
            ->assertSuccessful();

        $this->assertSame(1, Tenant::query()->count());
        $this->assertFalse(TenantUser::query()->where('user_id', $f['plain']->id)->exists());
        $this->assertSame(0, $this->rows($tenant, 'projects'));
        $this->assertNull(DB::table('status_page_routes')->where('slug', 'platform')->value('tenant_id'));
    }

    public function test_a_conversion_that_stopped_continues_where_it_stopped(): void
    {
        $f = $this->singleOrganization();

        $stopping = new SingleTenantConversion(function (string $level, string $message) {
            if ($message === 'Step central...') {
                throw new \RuntimeException('worker stopped');
            }
        });
        try {
            $stopping->run('Default', 'runr');
            $this->fail('the conversion should have stopped');
        } catch (\RuntimeException $e) {
            $this->assertSame('worker stopped', $e->getMessage());
        }

        $conversion = new SingleTenantConversion;
        $this->assertSame(SingleTenantConversion::STATUS_RESUME, $conversion->status());
        $this->assertFalse(tenancy()->initialized, 'a failed step leaves the central context');

        $this->artisan('tenancy:setup', ['--force' => true])->assertSuccessful();

        $tenant = Tenant::query()->sole();
        $this->assertSame(SingleTenantConversion::STATUS_DONE, $conversion->status());
        $this->assertSame(2, $this->rows($tenant, 'projects'));
        $this->assertSame(4, TenantUser::query()->count());
        $this->assertSame($tenant->id, DB::table('personal_access_tokens')->where('id', $f['ci']->id)->value('tenant_id'));
        $this->assertDirectoryExists(storage_path('git/runr/.git'));
    }

    public function test_a_dry_run_shows_the_plan_and_changes_nothing(): void
    {
        $this->singleOrganization();

        $this->artisan('tenancy:setup', ['--dry-run' => true, '--slug' => 'acme', '--name' => 'Acme'])
            ->expectsOutputToContain('Organization to create: Acme (acme)')
            ->expectsOutputToContain('olivia: owner')
            ->expectsOutputToContain('paul: viewer')
            ->expectsOutputToContain('New platform administrators: olivia, adam')
            ->expectsOutputToContain('projects: 2')
            ->expectsOutputToContain('rbac_role_assignments: 2')
            ->expectsOutputToContain('status_page_routes: 1')
            ->expectsOutputToContain('shop: orders → orders-acme')
            ->assertSuccessful();

        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, User::query()->where('is_admin', true)->count());
        $this->assertDirectoryExists(storage_path('git/.git'));
        $this->assertSame([], (new SingleTenantConversion)->state());
        // The temporary schema the tables were listed from is gone
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->assertSame([], DB::select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_setup-preview%'"));
        } else {
            $this->assertSame([], glob(database_path('*setup-preview*')));
        }
    }

    public function test_no_promote_keeps_platform_administrators_as_they_are(): void
    {
        $this->singleOrganization();

        $this->artisan('tenancy:setup', ['--force' => true, '--no-promote' => true])->assertSuccessful();

        $this->assertSame(0, User::query()->where('is_admin', true)->count());
        $this->assertSame('skipped', (new SingleTenantConversion)->state()['steps']['admins']);
    }

    public function test_a_fresh_installation_has_nothing_to_convert(): void
    {
        User::factory()->create(['username' => 'root']);
        RoleAssignment::query()->delete();

        $this->artisan('tenancy:setup', ['--force' => true])
            ->expectsOutputToContain('nothing to convert')
            ->assertSuccessful();

        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_an_installation_with_organizations_is_left_alone_and_admin_promotes_accounts(): void
    {
        $this->singleOrganization();
        Tenant::create(['name' => 'Globex', 'slug' => 'globex']);

        $this->artisan('tenancy:setup', ['--force' => true, '--admin' => ['dora']])
            ->expectsOutputToContain('already has organizations')
            ->expectsOutputToContain('Platform administrator: dora')
            ->assertSuccessful();

        $this->assertSame(1, Tenant::query()->count());
        $this->assertSame(['dora'], User::query()->where('is_admin', true)->pluck('username')->all());
        $this->assertDirectoryExists(storage_path('git/.git'));
    }

    /** --admin names accounts the way the sign-in does (App\Support\Auth\LoginIdentifier): one each. */
    public function test_admin_promotes_the_one_account_a_name_signs_in_as(): void
    {
        $this->singleOrganization();
        Tenant::create(['name' => 'Globex', 'slug' => 'globex']);
        User::factory()->create(['username' => 'mira', 'email' => 'Mira@Example.test']);
        // An account of an earlier release whose user name is that address: the address names Mira
        User::factory()->create(['username' => 'mira@example.test', 'email' => 'other@example.test']);

        $this->artisan('tenancy:setup', ['--force' => true, '--admin' => ['MIRA@example.test', 'DORA']])
            ->expectsOutputToContain('Platform administrator: MIRA@example.test')
            ->expectsOutputToContain('Platform administrator: DORA')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['dora', 'mira'], User::query()->where('is_admin', true)->pluck('username')->all());
    }

    public function test_switching_tenancy_on_makes_the_owners_and_admins_platform_administrators(): void
    {
        $this->singleOrganization();
        // The Tenancy migrations run when the module is switched on: users.is_admin arrives then
        // (false for every account), and the installation has no platform administrator
        Schema::table('users', fn ($table) => $table->dropColumn('is_admin'));

        (require base_path('Modules/Tenancy/Database/Migrations/2026_03_10_100001_add_is_admin_to_users.php'))->up();
        $this->assertSame(0, User::query()->where('is_admin', true)->count());
        (require base_path('Modules/Tenancy/Database/Migrations/2026_10_18_000001_promote_installation_administrators.php'))->up();

        $this->assertSame(['adam', 'olivia'], User::query()->where('is_admin', true)->orderBy('username')->pluck('username')->all());
    }

    public function test_an_installation_with_a_platform_administrator_keeps_its_administrators(): void
    {
        $f = $this->singleOrganization();
        $f['dev']->forceFill(['is_admin' => true])->save();

        (require base_path('Modules/Tenancy/Database/Migrations/2026_10_18_000001_promote_installation_administrators.php'))->up();

        $this->assertSame(['dora'], User::query()->where('is_admin', true)->pluck('username')->all());
    }

    // ── Every way of creating / migrating a tenant ──────────────────────────

    private function registerProbeMigrations(): void
    {
        TenantMigrationRegistry::register(base_path(self::REGISTRY_FIXTURE));
    }

    private function hasProbeTable(Tenant $tenant): bool
    {
        return $this->inTenant($tenant, fn () => Schema::hasTable($this->tenantTable($tenant, 'registry_probes')));
    }

    public function test_the_console_creates_tenants_with_every_modules_migrations(): void
    {
        $this->registerProbeMigrations();
        $creator = User::factory()->create();
        $request = Request::create('/api/tenants', 'POST', ['name' => 'Acme', 'slug' => 'acme']);
        $request->setUserResolver(fn () => $creator);

        app(TenantController::class)->store($request);

        $this->assertTrue($this->hasProbeTable(Tenant::query()->where('slug', 'acme')->sole()));
    }

    public function test_self_service_signups_create_tenants_with_every_modules_migrations(): void
    {
        $this->registerProbeMigrations();
        $user = User::factory()->create();

        (new CreateTenantOnRegistration)->handle(new UserRegistered($user, ['tenant_name' => 'Initech']));

        $this->assertTrue($this->hasProbeTable(Tenant::query()->where('slug', 'initech')->sole()));
    }

    public function test_the_create_tenant_command_runs_every_modules_migrations(): void
    {
        $this->registerProbeMigrations();

        $this->artisan('tenancy:create-tenant', ['name' => 'Umbrella', 'slug' => 'umbrella'])->assertSuccessful();

        $this->assertTrue($this->hasProbeTable(Tenant::query()->where('slug', 'umbrella')->sole()));
    }

    public function test_tenancy_migrate_adds_a_modules_new_tenant_migrations(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        TenantSchema::create($tenant);
        $this->assertFalse($this->hasProbeTable($tenant));

        $this->registerProbeMigrations();
        $this->artisan('tenancy:migrate')->assertSuccessful();

        $this->assertTrue($this->hasProbeTable($tenant));
    }

    public function test_migrate_fresh_empties_the_tenant_schema_and_nothing_else(): void
    {
        $this->registerProbeMigrations();
        $user = User::factory()->create(['username' => 'central-account']);
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        TenantSchema::create($tenant);
        $this->inTenant($tenant, fn () => DB::table($this->tenantTable($tenant, 'registry_probes'))->insert(['name' => 'old']));

        $this->artisan('tenancy:migrate', ['--fresh' => true, '--force' => true, '--tenant' => 'acme'])->assertSuccessful();

        $this->assertSame(0, $this->rows($tenant, 'registry_probes'), 'tenant tables recreated empty');
        $this->assertTrue($this->hasProbeTable($tenant));
        // Central tables untouched (migrate:fresh in tenant context would have dropped them)
        $this->assertTrue(Schema::hasTable('tenants'));
        $this->assertSame('central-account', User::query()->whereKey($user->id)->value('username'));
        $this->assertNotNull(Tenant::find($tenant->id));
    }
}
