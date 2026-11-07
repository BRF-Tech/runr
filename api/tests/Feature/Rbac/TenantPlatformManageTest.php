<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use App\Support\HorizonAccess;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Multi-tenant installs keep their platform administrators (users.is_admin) for the installation
 * itself: platform.manage does not exist there, so a tenant's Owner — in the tenant or in the
 * central context — never reaches the Patches page, the console certificate, the platform's
 * banners or Horizon, and a platform administrator's scoped token stays refused ('@platform').
 */
class TenantPlatformManageTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase, WiresTenancy;

    private const SCHEMA = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0020_add_created_by_to_projects.php',
        'Modules/Tenancy/Database/TenantMigrations/0040_create_rbac_tables.php',
    ];

    private const ROUTES = [
        ['GET', '/api/admin/runtime-patches'],
        ['POST', '/api/admin/runtime-patches/check'],
        ['GET', '/api/admin/console-tls'],
    ];

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true, 'runtime_patches.public_key' => '']);
        Http::fake();
        $this->acme = $this->createTenantWithSchema('acme', self::SCHEMA);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function member(Tenant $tenant, string $role): User
    {
        $user = User::factory()->create(['username' => 'u'.Str::lower(Str::random(10))]);
        DB::connection('central')->table('tenant_users')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function assertRefused(User $user, string $where): void
    {
        foreach (self::ROUTES as [$method, $uri]) {
            $this->signedIn($user)->json($method, $uri)->assertForbidden()
                ->assertJsonPath('message', __('rbac.platform_admin_required'))
                ->assertJsonMissingPath('permissions');
        }
        $this->assertFalse(app(Rbac::class)->operatesPlatform($user), $where);
        $this->assertFalse(app(HorizonAccess::class)->isAdministrator($user), "{$where}: Horizon");
    }

    public function test_a_tenant_owner_does_not_operate_the_installation(): void
    {
        $owner = $this->member($this->acme, 'owner');
        $admin = $this->member($this->acme, 'admin');

        // the central context (no tenant)
        $this->assertRefused($owner, 'central context');

        // in the tenant: Owner holds every permission of the edition — platform.manage is not one
        tenancy()->initialize($this->acme);
        $this->flushRbac();
        $this->assertNotContains(PermissionCatalog::PLATFORM_MANAGE, app(Rbac::class)->rolePermissions('owner'));
        $this->assertTrue($this->can($owner, 'roles.manage'));
        foreach ([$owner, $admin] as $user) {
            $this->assertRefused($user, 'tenant');
            $this->signedIn($user)->postJson('/api/horizon/link')->assertForbidden();
        }
        $this->assertSame([], collect($this->signedIn($owner)->getJson('/api/runtime-patches/banners')->assertOk()->json('data'))->pluck('kind')->all());
        $this->assertNotContains(PermissionCatalog::PLATFORM_MANAGE, collect($this->signedIn($owner)->getJson('/api/permissions')->json('permissions'))->pluck('key')->all());
    }

    public function test_platform_administrators_operate_it_as_before_and_their_scoped_tokens_do_not(): void
    {
        $root = $this->platformAdmin();

        foreach (self::ROUTES as [$method, $uri]) {
            $this->assertAllowed($this->signedIn($root)->json($method, $uri), "{$method} {$uri}");
        }
        $this->assertContains('feed_disabled', collect($this->signedIn($root)->getJson('/api/runtime-patches/banners')->json('data'))->pluck('kind')->all());
        $this->assertTrue(app(HorizonAccess::class)->isAdministrator($root));

        // a token with a selection of permissions: '@platform' refuses it; platform.manage is no
        // ability a multi-tenant token can carry
        $scoped = $root->createToken('ops', ['regions.view', 'platform.manage'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        foreach (self::ROUTES as [$method, $uri]) {
            $this->withHeader('Authorization', 'Bearer '.$scoped)->json($method, $uri)->assertForbidden()
                ->assertJsonPath('message', __('rbac.platform_admin_required'));
        }
    }
}
