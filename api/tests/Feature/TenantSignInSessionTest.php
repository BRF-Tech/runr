<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Tenancy\Http\Controllers\TenancyApiTokenController;
use Modules\Tenancy\Http\Controllers\TenancyAuthController;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantInvitation;
use Modules\Tenancy\Models\TenantUser;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Sign-in sessions of the multi-tenant edition (docs/security.md, "Sign-in sessions and API
 * tokens"): a Tenant user's sign-in and an accepted invitation carry the session lifetime, and
 * signing out or deleting a tenant's API token ends the token for good. Tokens of every tenant live
 * in the central table.
 */
class TenantSignInSessionTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();

        // The suite runs with the Tenancy module off: its controllers and public routes the way it
        // binds and registers them
        $this->app->bind(AuthController::class, TenancyAuthController::class);
        $this->app->bind(ApiTokenController::class, TenancyApiTokenController::class);
        Router::middleware('api')->prefix('api')->group(base_path('Modules/Tenancy/routes/public.php'));
        Router::getRoutes()->refreshNameLookups();
        Router::getRoutes()->refreshActionLookups();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function me(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/auth/me');
    }

    private function assertSessionLifetime(string $plain, string $name): void
    {
        $token = PersonalAccessToken::findToken($plain);
        $this->assertSame($name, $token->name);
        $this->assertSame(now()->addDays(30)->toDateTimeString(), $token->expires_at?->toDateTimeString());
    }

    public function test_a_tenant_users_sign_in_session_ends_when_signed_out(): void
    {
        $this->freezeSecond();
        $owner = User::factory()->create(['username' => 'owner', 'password' => 'owner-password']);
        $jane = User::factory()->create(['username' => 'jane', 'password' => 'jane-password']);
        TenantUser::create(['tenant_id' => $this->tenant->id, 'user_id' => $jane->id, 'role' => 'developer']);

        $login = $this->postJson('/api/auth/login', ['login' => 'jane', 'password' => 'jane-password', 'tenant_slug' => 'acme'])
            ->assertOk()
            ->assertJsonPath('active_tenant.slug', 'acme');
        $token = $login->json('token');
        $this->assertSessionLifetime($token, 'api');
        $this->me($token)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->me($token)->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $jane->id)->count());

        // Not a member of the tenant: the token of the refused sign-in is gone at once
        $this->flushHeaders();
        $this->postJson('/api/auth/login', ['login' => 'owner', 'password' => 'owner-password', 'tenant_slug' => 'acme'])->assertForbidden();
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $owner->id)->count());
    }

    public function test_an_accepted_invitation_signs_in_with_the_session_lifetime(): void
    {
        $this->freezeSecond();
        User::factory()->create(['username' => 'owner']);
        [$plain, $hash] = TenantInvitation::newToken();
        TenantInvitation::create([
            'tenant_id' => $this->tenant->id, 'email' => 'new@example.test', 'role' => 'developer',
            'token' => $hash, 'expires_at' => now()->addDays(7),
        ]);

        $token = $this->postJson("/api/invitations/{$plain}/accept", ['name' => 'New Member', 'password' => 'secret-password'])
            ->assertOk()
            ->json('token');
        $this->assertSessionLifetime($token, 'invitation-accept');
        $this->me($token)->assertOk()->assertJsonPath('email', 'new@example.test');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->me($token)->assertUnauthorized();
    }

    public function test_a_deleted_tenant_api_token_stays_deleted(): void
    {
        $owner = User::factory()->create(['username' => 'owner']);
        $session = $owner->createToken('api')->plainTextToken;
        $ci = $owner->createToken('ci');

        $this->app['auth']->forgetGuards();
        $this->withToken($session)->deleteJson('/api/tokens/'.$ci->accessToken->id)->assertOk();
        $this->me($ci->plainTextToken)->assertUnauthorized();

        // The token deletes itself (`runr logout`)
        $cli = $owner->createToken('runr-cli@laptop');
        $this->app['auth']->forgetGuards();
        $this->withToken($cli->plainTextToken)->deleteJson('/api/tokens/'.$cli->accessToken->id)->assertOk();
        $this->me($cli->plainTextToken)->assertUnauthorized();
    }
}
