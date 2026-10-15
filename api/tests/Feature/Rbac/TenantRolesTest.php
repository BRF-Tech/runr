<?php

namespace Tests\Feature\Rbac;

use App\Models\Group;
use App\Models\Project;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Rbac\NoOrganization;
use App\Support\Rbac\OrganizationResolver;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Modules\Tenancy\Http\Middleware\IdentifyTenant;
use Modules\Tenancy\Http\Middleware\RequireAdmin;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Support\TenantOrganization;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Multi-tenant: the active tenant is the organization — the member's role is tenant_users.role
 * (built-in or custom), groups / custom roles / project access live in the tenant schema; the
 * central context grants nothing but to platform admins; upgraded "member" users keep exactly
 * what they could do; tokens stay in their tenant.
 */
class TenantRolesTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase, WiresTenancy;

    private const SCHEMA = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0020_add_created_by_to_projects.php',
        'Modules/Tenancy/Database/TenantMigrations/0040_create_rbac_tables.php',
    ];

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        $this->acme = $this->createTenantWithSchema('acme', self::SCHEMA);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function member(Tenant $tenant, string $role, ?User $user = null): User
    {
        $user ??= User::factory()->create(['username' => 'u'.Str::lower(Str::random(10))]);
        DB::connection('central')->table('tenant_users')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function inTenant(Tenant $tenant): void
    {
        tenancy()->initialize($tenant);
        $this->flushRbac();
    }

    public function test_the_active_tenant_is_the_organization(): void
    {
        $this->inTenant($this->acme);

        $this->assertInstanceOf(TenantOrganization::class, app(OrganizationResolver::class)->current());
    }

    public function test_member_roles_come_from_the_tenant_membership(): void
    {
        $owner = $this->member($this->acme, 'owner');
        $admin = $this->member($this->acme, 'admin');
        $dev = $this->member($this->acme, 'developer');
        $viewer = $this->member($this->acme, 'viewer');
        $this->inTenant($this->acme);

        $this->assertTrue($this->can($owner, 'roles.manage'));
        $this->assertTrue($this->can($admin, 'regions.manage'));
        $this->assertTrue($this->can($dev, 'projects.deploy'));
        $this->assertFalse($this->can($dev, 'members.invite'));
        $this->assertTrue($this->can($viewer, 'projects.view'));
        $this->assertFalse($this->can($viewer, 'projects.deploy'), 'viewer is read-only now');
    }

    public function test_the_billing_role_in_multi_tenant_installs(): void
    {
        config(['tenancy.enabled' => true]);
        $billing = $this->member($this->acme, 'billing');
        $this->inTenant($this->acme);

        $this->assertTrue($this->can($billing, 'billing.manage'));
        $this->assertFalse($this->can($billing, 'projects.view'));
    }

    public function test_members_of_another_tenant_have_no_permissions_here(): void
    {
        $globex = $this->createTenantWithSchema('globex', self::SCHEMA);
        $stranger = $this->member($globex, 'owner');
        $this->inTenant($this->acme);

        $this->assertFalse($this->can($stranger, 'projects.view'));
    }

    public function test_the_central_context_is_for_platform_admins_only(): void
    {
        config(['tenancy.enabled' => true]);
        $owner = $this->member($this->acme, 'owner');
        $root = $this->platformAdmin();

        $this->assertInstanceOf(NoOrganization::class, app(OrganizationResolver::class)->current());
        $this->assertFalse($this->can($owner, 'projects.view'));
        $this->assertTrue($this->can($root, 'regions.manage'));
    }

    public function test_custom_roles_groups_and_project_access_belong_to_the_tenant(): void
    {
        $globex = $this->createTenantWithSchema('globex', self::SCHEMA);
        $acmeUser = $this->member($this->acme, 'release');
        $globexUser = $this->member($globex, 'release');

        $this->acme->run(fn () => Role::query()->create([
            'key' => 'release', 'name' => 'Release', 'permissions' => PermissionCatalog::expand(['projects.deploy']),
        ]));

        $this->inTenant($this->acme);
        $this->assertTrue($this->can($acmeUser, 'projects.deploy'));
        $project = Project::query()->create(['name' => 'shop', 'service_type' => 'caas']);
        $group = $this->groupWithRole('ops', 'admin', [$acmeUser]);
        $this->assertTrue($this->can($acmeUser, 'regions.manage'), 'group role in the tenant');
        $this->grantProject($acmeUser, $project, 'developer');
        tenancy()->end();

        $this->inTenant($globex);
        $this->assertFalse($this->can($globexUser, 'projects.deploy'), 'the role is acme\'s');
        $this->assertSame(0, Group::query()->count());
        $this->assertSame(0, RoleAssignment::query()->count());
        $this->assertNotNull($group);
    }

    // ── Upgrade ─────────────────────────────────────────────────────────────

    public function test_upgraded_members_keep_what_they_could_do(): void
    {
        $member = $this->member($this->acme, 'member');
        $this->acme->run(fn () => (require base_path('Modules/Tenancy/Database/TenantMigrations/0041_create_legacy_member_role.php'))->up());
        $this->inTenant($this->acme);

        $role = Role::query()->where('key', 'member')->first();
        $this->assertNotNull($role, 'custom role Member');
        $this->assertSame('Member', $role->name);
        foreach (['projects.deploy', 'shell.exec', 'settings.manage', 'sso.manage', 'members.view'] as $permission) {
            $this->assertTrue($this->can($member, $permission), "member keeps {$permission}");
        }
        foreach (['members.invite', 'regions.manage', 'roles.manage', 'billing.view'] as $permission) {
            $this->assertFalse($this->can($member, $permission), "member never had {$permission}");
        }
    }

    public function test_tenants_without_members_get_no_member_role(): void
    {
        $this->member($this->acme, 'owner');
        $this->acme->run(fn () => (require base_path('Modules/Tenancy/Database/TenantMigrations/0041_create_legacy_member_role.php'))->up());

        $this->acme->run(fn () => $this->assertNull(Role::query()->where('key', 'member')->first()));
    }

    public function test_a_member_role_without_the_upgrade_role_falls_back_to_developer(): void
    {
        $member = $this->member($this->acme, 'member');
        $this->inTenant($this->acme);

        $this->assertTrue($this->can($member, 'projects.deploy'));
        $this->assertFalse($this->can($member, 'settings.manage'));
    }

    // ── Tenant member API ───────────────────────────────────────────────────

    private function tenantRoutes(): void
    {
        $this->app['router']->aliasMiddleware('tenancy.admin', RequireAdmin::class);
        Router::middleware(['api', 'auth:sanctum'])->prefix('api')->group(base_path('Modules/Tenancy/routes/api.php'));
        Router::getRoutes()->refreshNameLookups();
        Router::getRoutes()->refreshActionLookups();
    }

    public function test_tenant_roles_are_changed_through_the_tenant_users_api(): void
    {
        $this->tenantRoutes();
        $owner = $this->member($this->acme, 'owner');
        $admin = $this->member($this->acme, 'admin');
        $dev = $this->member($this->acme, 'developer');
        $this->acme->run(fn () => Role::query()->create(['key' => 'qa', 'name' => 'QA', 'permissions' => ['projects.view']]));
        $this->inTenant($this->acme);
        $url = "/api/tenants/{$this->acme->id}/users/{$dev->id}";

        $this->signedIn($owner)->patchJson($url, ['role' => 'qa'])->assertOk();
        $this->assertSame('qa', DB::connection('central')->table('tenant_users')->where('user_id', $dev->id)->value('role'));

        $this->signedIn($admin)->patchJson($url, ['role' => 'owner'])->assertForbidden();
        $this->signedIn($owner)->patchJson($url, ['role' => 'nope'])->assertUnprocessable();
        $this->signedIn($dev)->patchJson("/api/tenants/{$this->acme->id}/users/{$admin->id}", ['role' => 'viewer'])->assertForbidden();
    }

    public function test_tenant_member_lists_and_invitations_need_their_permissions(): void
    {
        $this->tenantRoutes();
        $viewer = $this->member($this->acme, 'viewer');
        $this->inTenant($this->acme);

        $this->signedIn($viewer)->getJson("/api/tenants/{$this->acme->id}/users")->assertOk();
        $this->signedIn($viewer)->postJson("/api/tenants/{$this->acme->id}/users", ['email' => 'new@example.test'])->assertForbidden();
    }

    public function test_the_path_tenant_must_be_the_active_tenant(): void
    {
        $this->tenantRoutes();
        $globex = $this->createTenantWithSchema('globex', self::SCHEMA);
        $user = $this->member($this->acme, 'owner');
        $this->member($globex, 'viewer', $user);
        $this->inTenant($this->acme);

        // Owner in acme, viewer in globex: acme's role does not manage globex's members
        $this->signedIn($user)->getJson("/api/tenants/{$globex->id}/users")->assertForbidden();
    }

    // ── Tokens ──────────────────────────────────────────────────────────────

    public function test_a_token_created_in_a_tenant_works_in_that_tenant_only(): void
    {
        config(['tenancy.enabled' => true]);
        $globex = $this->createTenantWithSchema('globex', self::SCHEMA);
        $user = $this->member($this->acme, 'developer');
        $this->member($globex, 'developer', $user);

        $token = $user->createToken('ci', ['projects.view'])->accessToken;
        $token->forceFill(['tenant_id' => $this->acme->id])->save();
        $user->withAccessToken($token);

        $request = function (?string $header) use ($user) {
            $r = Request::create('/api/projects', 'GET');
            $r->setUserResolver(fn () => $user);
            if ($header) {
                $r->headers->set('X-Tenant-ID', $header);
            }

            return $r;
        };

        (new IdentifyTenant)->handle($request(null), fn () => response('ok'));
        $this->assertSame($this->acme->id, tenancy()->tenant?->id, 'the token selects its tenant');
        tenancy()->end();

        try {
            (new IdentifyTenant)->handle($request($globex->id), fn () => response('ok'));
            $this->fail('a token of acme was used in globex');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
