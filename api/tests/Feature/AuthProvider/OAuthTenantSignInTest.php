<?php

namespace Tests\Feature\AuthProvider;

use App\Models\SignInHandoff;
use App\Models\User;
use App\Services\Auth\SignInHandoffService;
use App\Support\Auth\LoginTokens;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\AuthProvider\Controllers\OAuthController;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Models\SocialAccount;
use Modules\AuthProvider\Providers\AuthProviderServiceProvider;
use Modules\Tenancy\Http\Controllers\TenancyOAuthController;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Psr\Http\Message\RequestInterface;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * OAuth sign-in on a tenant's sign-in page: the redirect enters the tenant and uses the tenant's
 * provider, the callback enters the same tenant and exchanges the code with the same client — so
 * providers that exist only in a tenant work, and the platform's providers stay the platform's.
 * The handoff names the tenant, so the console starts in it (members and platform administrators
 * only), like after a password sign-in on that page.
 */
class OAuthTenantSignInTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private const FRONTEND = 'https://console.example.test';

    /** What the tenant schema needs for an SSO sign-in in this suite (SQLite: tokens live with the user) */
    private const TENANT_MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0003_create_users_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0010_create_auth_providers_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0011_create_social_accounts_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0018_add_provider_specific_fields_to_auth_providers.php',
        'Modules/Tenancy/Database/TenantMigrations/0032_add_saml_fields_to_auth_providers.php',
        'Modules/Tenancy/Database/TenantMigrations/0040_create_rbac_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0042_add_sso_group_mapping.php',
        'database/migrations/2026_02_20_182309_create_personal_access_tokens_table.php',
    ];

    private MockHandler $mock;

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private Tenant $tenant;

    private User $jane;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        // Tenant-scoped cache: stancl's CacheTenancyBootstrapper builds a new cache manager at every
        // initialization, and the suite's `array` store lives in its manager — a state stored while
        // the redirect ran in the tenant would be gone when the callback enters the tenant again
        // (installations use Redis). The redirect and the callback enter the same tenant either way.
        config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

        config([
            'app.url' => 'https://runr.example.test',
            'app.frontend_url' => self::FRONTEND,
            'authprovider.enabled' => true,
            'tenancy.enabled' => true,
        ]);
        $this->app->register(AuthProviderServiceProvider::class, true);
        // The suite runs with the Tenancy module off: its exchange answer the way it binds it
        $this->app->bind(OAuthController::class, TenancyOAuthController::class);
        Artisan::call('migrate', ['--path' => 'Modules/AuthProvider/Database/Migrations', '--force' => true]);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
        Log::spy();

        $this->mock = new MockHandler;
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        config(['authprovider.guzzle' => ['handler' => $stack]]);

        $this->jane = User::factory()->create(['username' => 'jane', 'email' => 'jane@acme.test']);

        $this->tenant = $this->createTenantWithSchema('acme', self::TENANT_MIGRATIONS);
        $this->tenant->run(function () {
            // The tenant's synced copy of Jane's account
            DB::table('users')->insert([
                'id' => $this->jane->id, 'name' => 'Jane', 'username' => 'jane', 'email' => 'jane@acme.test',
                'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->provider('acme-client', 'acme-secret');
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function provider(string $clientId, string $secret): AuthProvider
    {
        return AuthProvider::query()->create([
            'name' => 'Google', 'driver' => 'google', 'client_id' => $clientId, 'client_secret' => $secret,
            'is_enabled' => true, 'auto_register' => false,
        ]);
    }

    /** Every request of the test client is a request of its own: none inherits a tenant context. */
    private function request(callable $call): TestResponse
    {
        try {
            return $call();
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    /** @return array<string, string> */
    private function redirectQuery(string $suffix = ''): array
    {
        $url = $this->request(fn () => $this->getJson('/api/oauth/google/redirect'.$suffix))->assertOk()->json('redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function signInAtGoogle(string $state, string $email): TestResponse
    {
        foreach ([
            ['access_token' => 'google-access-token', 'expires_in' => 3599, 'token_type' => 'Bearer'],
            ['sub' => '1100220033', 'email' => $email, 'email_verified' => true, 'name' => 'Jane Doe'],
        ] as $body) {
            $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body)));
        }

        return $this->request(fn () => $this->get('/oauth/google/callback?'.http_build_query(['code' => 'code-1', 'state' => $state])));
    }

    /** @return array<string, string> */
    private function tokenRequest(): array
    {
        parse_str((string) $this->history[0]['request']->getBody(), $form);

        return $form;
    }

    public function test_a_tenant_only_provider_signs_in_from_redirect_to_callback(): void
    {
        // No provider in the platform's own organization at all
        $this->assertSame(0, AuthProvider::count());

        $query = $this->redirectQuery('?tenant=acme');
        $this->assertSame('acme-client', $query['client_id']);

        $response = $this->signInAtGoogle($query['state'], 'jane@acme.test');

        $response->assertRedirect();
        $this->assertStringStartsWith(self::FRONTEND.'/oauth/callback?code=', (string) $response->headers->get('Location'));
        $this->assertSame('acme-client', $this->tokenRequest()['client_id']);
        $this->assertSame('acme-secret', $this->tokenRequest()['client_secret']);

        // The link lives in the tenant's schema
        $this->assertSame(0, SocialAccount::count());
        $this->assertSame(1, $this->tenant->run(fn () => SocialAccount::where('provider_user_id', '1100220033')->count()));
        $this->assertSame(1, SignInHandoff::query()->count());
    }

    /** The one-time code of the callback's redirect to the console. */
    private function handoffCode(TestResponse $callback): string
    {
        $callback->assertRedirect();
        parse_str((string) parse_url((string) $callback->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query, (string) $callback->headers->get('Location'));

        return $query['code'];
    }

    private function exchange(string $code): TestResponse
    {
        return $this->request(fn () => $this->postJson('/api/oauth/exchange', ['code' => $code]));
    }

    public function test_a_tenant_sign_in_starts_the_console_in_the_tenant(): void
    {
        TenantUser::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->jane->id, 'role' => 'developer']);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        TenantUser::create(['tenant_id' => $other->id, 'user_id' => $this->jane->id, 'role' => 'owner']);

        $code = $this->handoffCode($this->signInAtGoogle($this->redirectQuery('?tenant=acme')['state'], 'jane@acme.test'));
        $this->assertSame($this->tenant->id, SignInHandoff::query()->value('tenant_id'));

        // Like the password sign-in on the tenant's page: every organization of the account, the
        // console starts in the one whose page the sign-in started from (not the first by name)
        $this->exchange($code)
            ->assertOk()
            ->assertJsonPath('user_id', (string) $this->jane->id)
            ->assertJsonPath('active_tenant.id', $this->tenant->id)
            ->assertJsonPath('active_tenant.slug', 'acme')
            ->assertJsonPath('active_tenant.role', 'developer')
            ->assertJsonCount(2, 'tenants')
            ->assertJsonStructure(['token', 'user_id', 'tenants' => [['id', 'name', 'slug', 'role']], 'active_tenant']);
    }

    public function test_a_platform_sign_in_starts_where_a_password_sign_in_would(): void
    {
        $this->provider('platform-client', 'platform-secret');
        TenantUser::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->jane->id, 'role' => 'developer']);

        $code = $this->handoffCode($this->signInAtGoogle($this->redirectQuery()['state'], 'jane@acme.test'));
        $this->assertNull(SignInHandoff::query()->value('tenant_id'));

        // A member: the first of their organizations
        $this->exchange($code)->assertOk()->assertJsonPath('active_tenant.slug', 'acme')->assertJsonCount(1, 'tenants');
    }

    public function test_a_tenant_sign_in_of_an_account_that_is_no_member_is_refused(): void
    {
        // Jane's copy is in the tenant's schema, her membership is gone
        $code = $this->handoffCode($this->signInAtGoogle($this->redirectQuery('?tenant=acme')['state'], 'jane@acme.test'));

        $this->exchange($code)->assertForbidden()->assertJsonPath('message', __('api.tenant.access_denied'))->assertJsonMissingPath('token');
        $this->exchange($code)->assertUnprocessable();
    }

    public function test_a_refused_exchange_revokes_the_session_and_an_administrator_gets_in(): void
    {
        $bob = User::factory()->create(['username' => 'bob']);
        $plain = LoginTokens::issue($bob)->plainTextToken;
        $handoffs = app(SignInHandoffService::class);

        $this->exchange($handoffs->issue($bob, $plain, $this->tenant->id))->assertForbidden();
        $this->assertNull(PersonalAccessToken::findToken($plain), 'the session of the refused sign-in is revoked');

        // A tenant deleted between the callback and the exchange
        $plain = LoginTokens::issue($bob)->plainTextToken;
        $this->exchange($handoffs->issue($bob, $plain, 'gone'))->assertForbidden()->assertJsonPath('message', __('api.tenant.not_found'));
        $this->assertNull(PersonalAccessToken::findToken($plain));

        // A platform administrator gets into any tenant, as with the password sign-in on its page
        $admin = User::factory()->create(['username' => 'root']);
        $admin->forceFill(['is_admin' => true])->save();
        $plain = LoginTokens::issue($admin)->plainTextToken;
        $this->exchange($handoffs->issue($admin, $plain, $this->tenant->id))
            ->assertOk()
            ->assertJsonPath('token', $plain)
            ->assertJsonPath('active_tenant.slug', 'acme')
            ->assertJsonPath('active_tenant.role', 'admin');
        $this->assertNotNull(PersonalAccessToken::findToken($plain));
    }

    public function test_the_platform_and_the_tenant_each_use_their_own_provider(): void
    {
        $this->provider('platform-client', 'platform-secret');

        $platform = $this->redirectQuery();
        $tenant = $this->redirectQuery('?tenant=acme');
        $this->assertSame('platform-client', $platform['client_id']);
        $this->assertSame('acme-client', $tenant['client_id']);

        // The tenant's state completes with the tenant's client, even though the platform has a Google provider
        $this->signInAtGoogle($tenant['state'], 'jane@acme.test')->assertRedirect();
        $this->assertSame('acme-client', $this->tokenRequest()['client_id']);
        $this->assertSame(1, $this->tenant->run(fn () => SocialAccount::count()));

        // And the platform's state with the platform's
        $this->history = [];
        $this->signInAtGoogle($platform['state'], 'jane@acme.test')->assertRedirect();
        $this->assertSame('platform-client', $this->tokenRequest()['client_id']);
        $this->assertSame(1, SocialAccount::count());
    }

    public function test_the_sign_in_page_of_a_tenant_lists_the_tenants_providers_only(): void
    {
        $this->provider('platform-client', 'platform-secret');
        $this->tenant->run(fn () => AuthProvider::query()->update(['name' => 'Acme Google']));

        $this->request(fn () => $this->getJson('/api/auth-providers/discover?tenant=acme'))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Acme Google');
        $this->request(fn () => $this->getJson('/api/auth-providers/discover'))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Google');
        $this->request(fn () => $this->getJson('/api/auth-providers/discover?tenant=nope'))
            ->assertOk()->assertExactJson([]);
    }

    public function test_an_unknown_tenant_has_no_providers(): void
    {
        $this->provider('platform-client', 'platform-secret');

        $this->request(fn () => $this->getJson('/api/oauth/google/redirect?tenant=nope'))
            ->assertNotFound()->assertJsonPath('message', __('api.oauth.provider_not_found'));
    }
}
