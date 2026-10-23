<?php

namespace Tests\Feature\Runtimes;

use App\Mail\RuntimePatchMail;
use App\Models\Deployment;
use App\Models\Notification;
use App\Models\Project;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchMigration;
use App\Models\User;
use App\Models\Version;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\Runtimes\Patches\RuntimePatchMigrations;
use App\Services\Runtimes\Patches\RuntimePatchNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Tests\Concerns\BuildsRuntimePatches;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Runtime patches in the multi-tenant edition: the patches are central, a tenant's projects and
 * their patch migrations live in its schema. A critical patch reaches the tenant's owners and
 * admins (its tenant administrators) and the owners of its projects in the tenant's inbox, the
 * platform administrators in the central one; the deadline job migrates tenant projects in their
 * schema.
 */
class RuntimePatchTenancyTest extends TestCase
{
    use BuildsRuntimePatches, RefreshDatabase, WiresTenancy;

    private const TENANT_MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0003_create_users_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0005_add_migration_fields_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0006_add_sandbox_and_kata_hypervisor_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0007_add_user_id_to_notifications.php',
        'Modules/Tenancy/Database/TenantMigrations/0008_add_region_status_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0015_create_notification_preferences_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0020_add_created_by_to_projects.php',
        'Modules/Tenancy/Database/TenantMigrations/0058_add_owner_and_stop_to_projects.php',
        'Modules/Tenancy/Database/TenantMigrations/0062_add_deployment_spans_and_stop_level.php',
        'Modules/Tenancy/Database/TenantMigrations/0068_add_runtime_patch_columns.php',
    ];

    private Tenant $tenant;

    private User $tenantOwner;

    private User $tenantAdmin;

    private User $developer;

    private User $outsider;

    private User $platformAdmin;

    private string $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);

        $this->tenant = $this->createTenantWithSchema('acme'.Str::lower(Str::random(4)), self::TENANT_MIGRATIONS);
        foreach (['tenantOwner' => 'owner', 'tenantAdmin' => 'admin', 'developer' => 'developer'] as $property => $role) {
            $this->{$property} = User::factory()->create(['username' => $property.Str::lower(Str::random(5))]);
            TenantUser::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->{$property}->id, 'role' => $role]);
        }
        $this->outsider = User::factory()->create();
        $this->platformAdmin = User::factory()->create();
        $this->platformAdmin->forceFill(['is_admin' => true])->save();

        $running = $this->runtimePatch(['tag' => '3.14.6-r1', 'published_at' => now()->subDays(9)]);
        $developer = $this->developer;
        $this->projectId = $this->tenant->run(function () use ($running, $developer) {
            $project = Project::query()->create(['name' => 'orders', 'service_type' => 'xaas', 'runtime' => 'python', 'runtime_version' => '3.14']);
            $project->forceFill(['owner_id' => $developer->id, 'runtime_patch_id' => $running->id, 'runtime_digest' => $running->digest])->save();
            $version = Version::query()->create(['project_id' => $project->id, 'number' => 1, 'commit_id' => 'abc', 'created_by' => 'dev', 'build_status' => 'ready']);
            Deployment::query()->create([
                'project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active', 'deployed_by' => $developer->username,
                'runtime_digest' => $running->digest, 'runtime_patch_id' => $running->id,
            ]);

            return (string) $project->id;
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_a_critical_patch_reaches_the_tenant_its_projects_and_the_platform(): void
    {
        $patch = $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::CRITICAL, 'published_at' => now()->subDay(), 'deadline_at' => now()->addDays(5)]);

        app(RuntimePatchNotifier::class)->announce($patch);

        $inTenant = $this->tenant->run(fn () => Notification::query()->where('type', 'runtime_patch')->pluck('user_id')->map(fn ($id) => (string) $id)->sort()->values()->all());
        $this->assertSame(collect([$this->tenantOwner->id, $this->tenantAdmin->id, $this->developer->id])->map(fn ($id) => (string) $id)->sort()->values()->all(), $inTenant);

        $central = Notification::query()->where('type', 'runtime_patch')->pluck('user_id')->map(fn ($id) => (string) $id)->all();
        $this->assertSame([(string) $this->platformAdmin->id], $central);

        Mail::assertQueued(RuntimePatchMail::class, fn (RuntimePatchMail $m) => $m->hasTo($this->tenantAdmin->email));
        Mail::assertNotQueued(RuntimePatchMail::class, fn (RuntimePatchMail $m) => $m->hasTo($this->outsider->email));
    }

    public function test_the_deadline_migrates_tenant_projects_in_their_schema(): void
    {
        $patch = $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::ZERO_DAY, 'published_at' => now()->subDays(3), 'deadline_at' => now()->subHour()]);
        $this->mock(DeploymentStarter::class, function ($mock) {
            // the deploy itself (checks, Knative) is not under test here: a pending deployment
            $mock->shouldReceive('start')->once()->andReturnUsing(function (Project $project, Version $version, string $by, $initiator, $config, $claim, array $runtime) {
                return Deployment::query()->create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending', 'deployed_by' => $by] + $runtime);
            });
        });

        app(RuntimePatches::class)->flush();
        $this->assertSame(1, app(RuntimePatchMigrations::class)->enforceDeadlines());

        $migration = $this->tenant->run(fn () => RuntimePatchMigration::query()->sole());
        $this->assertSame([$this->projectId, $patch->id, RuntimePatchMigration::AUTO], [$migration->project_id, $migration->patch_id, $migration->trigger]);
        $this->assertSame($patch->digest, $this->tenant->run(fn () => Deployment::query()->whereKey($migration->deployment_id)->value('runtime_digest')));
    }
}
