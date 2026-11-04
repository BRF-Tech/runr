<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Middleware\AuthorizeApiRoute;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Tenancy\Http\Controllers\TenancyApiTokenController;
use Modules\Tenancy\Http\Middleware\IdentifyTenant;
use Modules\Tenancy\Models\Tenant;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * API tokens of an organization in the multi-tenant edition (TenancyApiTokenController): a member
 * whose role holds tokens.manage lists every token created in the organization; a member without
 * it lists and revokes their own only, and creates none.
 */
class TenantOwnApiTokensTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);

        // As TenancyServiceProvider wires it: the tenant's token controller, IdentifyTenant after
        // authentication and before the route's permission check
        $this->app->bind(ApiTokenController::class, TenancyApiTokenController::class);
        $router = $this->app['router'];
        $priority = $router->middlewarePriority;
        $at = array_search(AuthorizeApiRoute::class, $priority, true);
        array_splice($priority, $at === false ? count($priority) : $at, 0, [IdentifyTenant::class]);
        $router->middlewarePriority = $priority;
        $router->pushMiddlewareToGroup('api', IdentifyTenant::class);

        // The organization is identified, its database is not switched to: the tokens live in the
        // central table, which an install's tenant schema reaches through its search_path and a
        // separate SQLite database cannot — the organization's custom role sits next to them
        config(['tenancy.bootstrappers' => []]);
        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        Role::query()->create(['key' => 'reader', 'name' => 'Reader', 'permissions' => ['projects.view']]);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['username' => 'u'.Str::lower(Str::random(10))]);
        DB::connection('central')->table('tenant_users')->insert([
            'tenant_id' => $this->acme->id, 'user_id' => $user->id, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function tokenIn(User $user, string $name): int
    {
        $token = $user->createToken($name)->accessToken;
        $token->forceFill(['tenant_id' => $this->acme->id])->save();

        return (int) $token->getKey();
    }

    private function send(User $user, string $method, string $uri, array $data = []): TestResponse
    {
        $headers = ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'X-Tenant-ID' => $this->acme->id];

        try {
            return $this->json($method, $uri, $data, $headers);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_a_member_without_tokens_manage_lists_and_revokes_their_own_tokens_only(): void
    {
        $owner = $this->member('owner');
        $reader = $this->member('reader');
        $ownerCi = $this->tokenIn($owner, 'owner-ci');
        $readerCi = $this->tokenIn($reader, 'reader-ci');

        $this->assertEqualsCanonicalizing([$ownerCi, $readerCi], array_column($this->send($owner, 'GET', '/api/tokens')->assertOk()->json('tokens'), 'id'));

        $listed = $this->send($reader, 'GET', '/api/tokens')->assertOk()->json('tokens');
        $this->assertSame([$readerCi], array_column($listed, 'id'));
        $this->assertTrue($listed[0]['mine']);

        $this->send($reader, 'POST', '/api/tokens', ['name' => 'new'])->assertForbidden();
        $this->send($reader, 'DELETE', "/api/tokens/{$ownerCi}")->assertNotFound();
        $this->assertNotNull(PersonalAccessToken::find($ownerCi));

        $this->send($reader, 'DELETE', "/api/tokens/{$readerCi}")->assertOk();
        $this->assertNull(PersonalAccessToken::find($readerCi));
    }
}
