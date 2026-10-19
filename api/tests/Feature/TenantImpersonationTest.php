<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthorizeApiRoute;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\TestResponse;
use Modules\Tenancy\Http\Middleware\IdentifyTenant;
use Modules\Tenancy\Http\Middleware\RequireAdmin;
use Modules\Tenancy\Models\Tenant;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * A platform administrator opens an organization as its administrator (docs/tenancy.md): the
 * console sends the organization's X-Tenant-ID (IdentifyTenant scopes platform administrators to
 * any tenant), POST /impersonate/{tenant} and POST /impersonate/stop mark the start and the end
 * in the audit log, GET /tenant/current tells the console that the administrator is no member.
 */
class TenantImpersonationTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);

        // The Tenancy module's routes behind IdentifyTenant, the way its provider registers them:
        // after authentication, before the route's permission check
        $router = $this->app['router'];
        $router->aliasMiddleware('tenancy.admin', RequireAdmin::class);
        $priority = $router->middlewarePriority;
        $at = array_search(AuthorizeApiRoute::class, $priority, true);
        array_splice($priority, $at === false ? count($priority) : $at, 0, [IdentifyTenant::class]);
        $router->middlewarePriority = $priority;
        Router::middleware(['api', 'auth:sanctum', IdentifyTenant::class])->prefix('api')->group(base_path('Modules/Tenancy/routes/api.php'));
        Router::getRoutes()->refreshNameLookups();
        Router::getRoutes()->refreshActionLookups();

        $this->acme = $this->createTenantWithSchema('acme');
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function user(bool $admin = false): User
    {
        static $n = 0;
        $user = User::factory()->create(['username' => 'imp-'.(++$n).'-'.uniqid()]);
        if ($admin) {
            $user->forceFill(['is_admin' => true])->save();
        }

        return $user->fresh();
    }

    private function member(Tenant $tenant, User $user, string $role = 'owner'): void
    {
        DB::connection('central')->table('tenant_users')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** One request with $user's sign-in session; the request's tenant context ends with it. */
    private function send(User $user, string $method, string $uri, array $data = [], ?Tenant $tenant = null): TestResponse
    {
        $token = $user->createToken('api')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        if ($tenant) {
            $headers['X-Tenant-ID'] = $tenant->id;
        }

        try {
            return $this->json($method, $uri, $data, $headers);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            $this->app['auth']->forgetGuards();
        }
    }

    private function audit(string $action): ?AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('occurred_at')->first();
    }

    public function test_a_platform_admin_starts_and_stops_opening_an_organization_both_audited(): void
    {
        $admin = $this->user(admin: true);

        $start = $this->send($admin, 'POST', "/api/impersonate/{$this->acme->id}");
        $start->assertOk()
            ->assertJsonPath('tenant.id', $this->acme->id)
            ->assertJsonPath('tenant.slug', 'acme')
            ->assertJsonPath('tenant.role', 'admin')
            ->assertJsonPath('tenant.impersonating', true)
            ->assertJsonPath('tenant.member', false);

        $event = $this->audit('impersonation.start');
        $this->assertNotNull($event, 'impersonation.start is audit-logged');
        $this->assertSame(AuditEvent::RESULT_SUCCESS, $event->result);
        $this->assertSame((string) $this->acme->id, $event->target_id);
        $this->assertSame((string) $admin->id, $event->actor_id);

        // While open: the console's requests carry the tenant, /tenant/current says so
        $current = $this->send($admin, 'GET', '/api/tenant/current', [], $this->acme);
        $current->assertOk()
            ->assertJsonPath('mode', 'tenant')
            ->assertJsonPath('tenant.id', $this->acme->id)
            ->assertJsonPath('impersonating', true);

        // Stop names the tenant being left (the request's X-Tenant-ID)
        $stop = $this->send($admin, 'POST', '/api/impersonate/stop', [], $this->acme);
        $stop->assertOk()->assertJsonPath('tenant_id', $this->acme->id);

        $event = $this->audit('impersonation.stop');
        $this->assertNotNull($event, 'impersonation.stop is audit-logged');
        $this->assertSame((string) $this->acme->id, $event->target_id);
    }

    public function test_stop_also_takes_the_tenant_from_the_body_and_needs_one(): void
    {
        $admin = $this->user(admin: true);

        $this->send($admin, 'POST', '/api/impersonate/stop', ['tenant_id' => $this->acme->id])
            ->assertOk()->assertJsonPath('tenant_id', $this->acme->id);

        $this->send($admin, 'POST', '/api/impersonate/stop')->assertStatus(422);
    }

    public function test_stopping_works_while_the_organization_is_being_deleted(): void
    {
        $admin = $this->user(admin: true);
        $this->acme->forceFill(['status' => Tenant::STATUS_DELETING])->save();

        $this->send($admin, 'POST', '/api/impersonate/stop', [], $this->acme)
            ->assertOk()->assertJsonPath('tenant_id', $this->acme->id);
    }

    public function test_only_platform_admins_open_organizations(): void
    {
        $owner = $this->user();
        $this->member($this->acme, $owner, 'owner');

        $this->send($owner, 'POST', "/api/impersonate/{$this->acme->id}")->assertForbidden();
        $this->send($owner, 'POST', '/api/impersonate/stop', ['tenant_id' => $this->acme->id])->assertForbidden();
        $this->assertSame(0, AuditEvent::query()
            ->whereIn('action', ['impersonation.start', 'impersonation.stop'])
            ->where('result', AuditEvent::RESULT_SUCCESS)
            ->count());
    }

    public function test_an_unknown_organization_is_404(): void
    {
        $this->send($this->user(admin: true), 'POST', '/api/impersonate/nope')->assertNotFound();
    }

    public function test_an_admin_in_an_organization_they_belong_to_is_no_impersonation(): void
    {
        $admin = $this->user(admin: true);
        $this->member($this->acme, $admin, 'owner');
        $globex = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);

        $this->send($admin, 'POST', "/api/impersonate/{$this->acme->id}")
            ->assertOk()->assertJsonPath('tenant.impersonating', false)->assertJsonPath('tenant.member', true);

        $current = $this->send($admin, 'GET', '/api/tenant/current', [], $this->acme);
        $current->assertOk()->assertJsonPath('impersonating', false);

        // Every organization is listed for a platform admin, with whether they belong to it
        $tenants = collect($current->json('tenants'))->keyBy('slug');
        $this->assertTrue($tenants['acme']['member']);
        $this->assertFalse($tenants['globex']['member']);
        $this->assertSame('admin', $tenants['globex']['role']);
    }

    public function test_members_see_their_organizations_and_are_never_impersonating(): void
    {
        $dev = $this->user();
        $this->member($this->acme, $dev, 'developer');
        Tenant::create(['name' => 'Globex', 'slug' => 'globex']);

        $current = $this->send($dev, 'GET', '/api/tenant/current', [], $this->acme);
        $current->assertOk()
            ->assertJsonPath('impersonating', false)
            ->assertJsonPath('role', 'developer');
        $this->assertSame([['acme', 'developer', true]], collect($current->json('tenants'))
            ->map(fn ($t) => [$t['slug'], $t['role'], $t['member']])->all());
    }

    public function test_switching_tells_whether_it_is_an_impersonation(): void
    {
        $admin = $this->user(admin: true);

        $this->send($admin, 'POST', '/api/tenant/switch', ['tenant_id' => $this->acme->id])
            ->assertOk()->assertJsonPath('tenant.id', $this->acme->id)->assertJsonPath('impersonating', true);
    }
}
