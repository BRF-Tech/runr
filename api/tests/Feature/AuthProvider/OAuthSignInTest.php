<?php

namespace Tests\Feature\AuthProvider;

use App\Models\PendingSignup;
use App\Models\User;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Models\SocialAccount;
use Modules\AuthProvider\Providers\AuthProviderServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * OAuth sign-in with the Socialite drivers (Google, GitLab): which provider row a sign-in uses
 * (explicit ID, else the first in sign-in order — the redirect and the callback use the same
 * one), Google's hosted domain (`hd` sent and checked) and GitLab subjects per instance. The
 * providers' HTTP endpoints answer from a Guzzle mock (authprovider.guzzle).
 */
class OAuthSignInTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private const FRONTEND = 'https://console.example.test';

    private MockHandler $mock;

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://runr.example.test',
            'app.frontend_url' => self::FRONTEND,
            'authprovider.enabled' => true,
        ]);
        $this->app->register(AuthProviderServiceProvider::class, true);
        Artisan::call('migrate', ['--path' => 'Modules/AuthProvider/Database/Migrations', '--force' => true]);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
        Log::spy();

        $this->userWithRole('owner', ['username' => 'admin', 'email' => 'admin@example.test']);

        $this->mock = new MockHandler;
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        config(['authprovider.guzzle' => ['handler' => $stack]]);
    }

    private function provider(string $driver, array $attributes = []): AuthProvider
    {
        return AuthProvider::query()->create(array_merge([
            'name' => ucfirst($driver),
            'driver' => $driver,
            'client_id' => "{$driver}-client",
            'client_secret' => "{$driver}-secret",
            'is_enabled' => true,
            'auto_register' => true,
        ], $attributes));
    }

    /** @return array<string, string> query of the authorization URL */
    private function redirectQuery(string $driver, array $params = []): array
    {
        $url = $this->getJson("/api/oauth/{$driver}/redirect".($params ? '?'.http_build_query($params) : ''))
            ->assertOk()->json('redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $query['_url'] = $url;

        return $query;
    }

    private function providerCallback(string $driver, string $state): TestResponse
    {
        return $this->get("/oauth/{$driver}/callback?".http_build_query(['code' => 'code-1', 'state' => $state]));
    }

    private function queueJson(array ...$bodies): void
    {
        foreach ($bodies as $body) {
            $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body)));
        }
    }

    private function queueGoogle(array $userinfo): void
    {
        $this->queueJson(
            ['access_token' => 'google-access-token', 'expires_in' => 3599, 'token_type' => 'Bearer', 'scope' => 'openid email profile'],
            $userinfo + ['sub' => '1100220033', 'email_verified' => true, 'name' => 'Jane Doe'],
        );
    }

    /** @return array<string, string> form fields of the n-th request to the provider */
    private function requestForm(int $index): array
    {
        parse_str((string) $this->history[$index]['request']->getBody(), $form);

        return $form;
    }

    private function assertSignedIn(TestResponse $response): void
    {
        $response->assertRedirect();
        $this->assertStringStartsWith(self::FRONTEND.'/oauth/callback?code=', $response->headers->get('Location'));
    }

    private function assertRefused(TestResponse $response, string $message): void
    {
        $response->assertRedirect(self::FRONTEND.'/login?oauth_error='.urlencode($message));
    }

    // ── Which provider ───────────────────────────────────────────────────────

    public function test_without_an_id_the_first_provider_in_sign_in_order_is_used(): void
    {
        $this->provider('google', ['name' => 'A Google', 'client_id' => 'client-a', 'display_order' => 2]);
        $z = $this->provider('google', ['name' => 'Z Google', 'client_id' => 'client-z', 'display_order' => 1]);
        $this->provider('google', ['name' => 'Disabled', 'client_id' => 'client-off', 'display_order' => 0, 'is_enabled' => false]);

        // Display order first, then name — the same provider on every request
        foreach (range(1, 3) as $ignored) {
            $this->assertSame('client-z', $this->redirectQuery('google')['client_id']);
        }

        $z->update(['display_order' => 2]);
        $this->assertSame('client-a', $this->redirectQuery('google')['client_id']);
    }

    public function test_the_redirect_and_the_callback_use_the_provider_named_by_id(): void
    {
        $this->provider('google', ['name' => 'Staff', 'client_id' => 'staff-client', 'client_secret' => 'staff-secret']);
        $partners = $this->provider('google', ['name' => 'Partners', 'client_id' => 'partner-client', 'client_secret' => 'partner-secret', 'display_order' => 5]);

        $query = $this->redirectQuery('google', ['provider' => $partners->id]);
        $this->assertSame('partner-client', $query['client_id']);
        $this->assertSame(url('/oauth/google/callback'), $query['redirect_uri']);

        $this->queueGoogle(['email' => 'jane@example.com']);
        $this->assertSignedIn($this->providerCallback('google', $query['state']));

        // The code was exchanged with the same client
        $token = $this->requestForm(0);
        $this->assertSame('partner-client', $token['client_id']);
        $this->assertSame('partner-secret', $token['client_secret']);
        $this->assertSame('1100220033', SocialAccount::query()->sole()->provider_user_id);
    }

    public function test_an_unknown_or_disabled_provider_is_not_found(): void
    {
        $disabled = $this->provider('google', ['is_enabled' => false]);
        $github = $this->provider('github');

        $this->getJson('/api/oauth/google/redirect')->assertNotFound()->assertJsonPath('message', __('api.oauth.provider_not_found'));
        $this->getJson('/api/oauth/google/redirect?provider='.$disabled->id)->assertNotFound();
        // An ID of another driver's provider
        $this->getJson('/api/oauth/google/redirect?provider='.$github->id)->assertNotFound();
        // SAML signs in through /api/saml/{id}/login
        $this->getJson('/api/oauth/saml/redirect')->assertNotFound();
    }

    public function test_a_tenant_never_falls_back_to_the_installations_providers(): void
    {
        $this->provider('google');

        // Single-tenant install: no tenant can match
        $this->getJson('/api/oauth/google/redirect?tenant=acme')->assertNotFound();
        $this->getJson('/api/auth-providers/discover?tenant=acme')->assertOk()->assertExactJson([]);
        $this->getJson('/api/auth-providers/discover')->assertOk()->assertJsonCount(1);
    }

    // ── Google hosted domain ─────────────────────────────────────────────────

    public function test_google_asks_for_the_hosted_domain_and_accepts_its_accounts(): void
    {
        $this->provider('google', ['google_hosted_domain' => 'example.com']);

        $query = $this->redirectQuery('google');
        $this->assertStringStartsWith('https://accounts.google.com/', $query['_url']);
        $this->assertSame('example.com', $query['hd']);

        $this->queueGoogle(['email' => 'jane@example.com', 'hd' => 'Example.com']);
        $this->assertSignedIn($this->providerCallback('google', $query['state']));
        $this->assertNotNull(User::where('email', 'jane@example.com')->first());
        // `hd` is not sent to the token endpoint
        $this->assertArrayNotHasKey('hd', $this->requestForm(0));
    }

    public static function foreignGoogleAccounts(): array
    {
        return [
            'personal account (no hd)' => [['email' => 'jane@gmail.com']],
            'another Workspace domain' => [['email' => 'jane@other.example', 'hd' => 'other.example']],
        ];
    }

    #[DataProvider('foreignGoogleAccounts')]
    public function test_google_refuses_accounts_outside_the_hosted_domain(array $userinfo): void
    {
        $this->provider('google', ['google_hosted_domain' => 'example.com']);
        $state = $this->redirectQuery('google')['state'];

        $this->queueGoogle($userinfo);
        $this->assertRefused($this->providerCallback('google', $state), __('api.oauth.hosted_domain_mismatch', ['domain' => 'example.com']));

        $this->assertNull(User::where('email', $userinfo['email'])->first());
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_google_without_a_hosted_domain_accepts_every_account(): void
    {
        $this->provider('google');
        $query = $this->redirectQuery('google');
        $this->assertArrayNotHasKey('hd', $query);

        $this->queueGoogle(['email' => 'jane@gmail.com']);
        $this->assertSignedIn($this->providerCallback('google', $query['state']));
    }

    // ── The account of the address ──────────────────────────────────────────

    public function test_the_providers_address_names_an_account_in_any_letter_case(): void
    {
        $this->provider('google');
        $jane = User::factory()->create(['username' => 'jane', 'email' => 'jane.doe@gmail.com']);

        $this->queueGoogle(['email' => 'Jane.Doe@GMAIL.com']);
        $this->assertSignedIn($this->providerCallback('google', $this->redirectQuery('google')['state']));

        $this->assertSame(2, User::query()->count(), 'no second account');
        $this->assertSame($jane->id, SocialAccount::query()->sole()->user_id);
    }

    public function test_a_new_account_gets_a_free_user_name_and_the_address_in_lower_case(): void
    {
        $this->provider('google');
        // Its user name in other letters, and a waiting signup's
        User::factory()->create(['username' => 'Jane-Doe', 'email' => 'someone@example.test']);
        PendingSignup::query()->create(['email' => 'other@example.test', 'username' => 'jane-doe-2', 'name' => 'O', 'password' => 'x',
            'token_hash' => 'h', 'expires_at' => now()->addHour(), 'send_count' => 1]);

        $this->queueGoogle(['email' => ' Jane.Doe@Gmail.com ', 'sub' => '77']);
        $this->assertSignedIn($this->providerCallback('google', $this->redirectQuery('google')['state']));

        $user = User::query()->where('email', 'jane.doe@gmail.com')->sole();
        $this->assertSame('jane-doe-3', $user->username);
    }

    public function test_an_address_several_accounts_share_in_other_letter_case_signs_in_nobody(): void
    {
        $this->provider('google');
        // Accounts of an earlier release that differ in case only
        User::factory()->create(['username' => 'jane1', 'email' => 'Jane@gmail.com']);
        User::factory()->create(['username' => 'jane2', 'email' => 'JANE@gmail.com']);

        $this->queueGoogle(['email' => 'jane@gmail.com']);
        $this->assertRefused($this->providerCallback('google', $this->redirectQuery('google')['state']), __('api.oauth.email_ambiguous'));
        $this->assertSame(3, User::query()->count(), 'no third account');
    }

    public function test_a_provider_error_is_shown_not_thrown(): void
    {
        $this->provider('google');
        $state = $this->redirectQuery('google')['state'];
        $this->mock->append(new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'));

        $this->assertRefused($this->providerCallback('google', $state), __('api.oauth.provider_error'));
    }

    // ── GitLab instances ─────────────────────────────────────────────────────

    private function queueGitlab(int $id, string $email): void
    {
        $this->queueJson(
            ['access_token' => 'gl-token', 'token_type' => 'Bearer', 'expires_in' => 7200],
            ['id' => $id, 'username' => 'jane', 'name' => 'Jane Doe', 'email' => $email, 'avatar_url' => null],
        );
    }

    public function test_a_self_managed_gitlab_scopes_its_user_ids_to_the_instance(): void
    {
        $this->provider('gitlab', ['gitlab_instance_uri' => 'https://gitlab.company.test']);

        $query = $this->redirectQuery('gitlab');
        $this->assertStringStartsWith('https://gitlab.company.test/oauth/authorize?', $query['_url']);

        $this->queueGitlab(42, 'jane@company.test');
        $this->assertSignedIn($this->providerCallback('gitlab', $query['state']));

        $this->assertSame('https://gitlab.company.test/oauth/token', (string) $this->history[0]['request']->getUri());
        $this->assertSame('gitlab.company.test:42', SocialAccount::query()->sole()->provider_user_id);
    }

    public function test_gitlab_com_keeps_bare_user_ids(): void
    {
        $this->provider('gitlab');
        $state = $this->redirectQuery('gitlab')['state'];

        $this->queueGitlab(42, 'jane@company.test');
        $this->assertSignedIn($this->providerCallback('gitlab', $state));

        $this->assertSame('42', SocialAccount::query()->sole()->provider_user_id);
    }

    public function test_accounts_linked_before_instance_scoping_move_to_the_only_instance(): void
    {
        $this->provider('gitlab', ['gitlab_instance_uri' => 'https://gitlab.company.test/']);
        $jane = User::factory()->create(['email' => 'jane@runr.test']);
        $legacy = SocialAccount::query()->create([
            'user_id' => $jane->id, 'provider' => 'gitlab', 'provider_user_id' => '42',
            'email' => 'jane@company.test', 'access_token' => '',
        ]);

        $state = $this->redirectQuery('gitlab')['state'];
        // Another address at GitLab: only the linked account can sign Jane in
        $this->queueGitlab(42, 'jane.doe@company.test');
        $this->assertSignedIn($this->providerCallback('gitlab', $state));

        $this->assertSame('gitlab.company.test:42', $legacy->fresh()->provider_user_id);
        $this->assertSame(1, SocialAccount::count());
        $this->assertNull(User::where('email', 'jane.doe@company.test')->first());
    }

    public function test_bare_ids_are_not_trusted_once_several_gitlab_instances_are_configured(): void
    {
        $this->provider('gitlab', ['name' => 'Company GitLab', 'gitlab_instance_uri' => 'https://gitlab.company.test']);
        $this->provider('gitlab', ['name' => 'GitLab.com', 'display_order' => 9]);
        $victim = User::factory()->create(['email' => 'victim@runr.test']);
        SocialAccount::query()->create([
            'user_id' => $victim->id, 'provider' => 'gitlab', 'provider_user_id' => '42',
            'email' => 'victim@gitlab.example', 'access_token' => '',
        ]);

        // User 42 of the company instance is not user 42 of gitlab.com
        $state = $this->redirectQuery('gitlab')['state'];
        $this->queueGitlab(42, 'mallory@company.test');
        $this->assertSignedIn($this->providerCallback('gitlab', $state));

        $mallory = User::where('email', 'mallory@company.test')->firstOrFail();
        $this->assertNotSame((string) $victim->id, (string) $mallory->id);
        $this->assertSame(2, SocialAccount::count());
    }
}
