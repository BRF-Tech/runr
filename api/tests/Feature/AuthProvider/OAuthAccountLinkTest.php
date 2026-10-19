<?php

namespace Tests\Feature\AuthProvider;

use App\Models\AuditEvent;
use App\Models\User;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Models\SocialAccount;
use Modules\AuthProvider\Providers\AuthProviderServiceProvider;
use Tests\Concerns\ActsWithRoles;
use Tests\Support\OidcTestIdp;
use Tests\TestCase;

/**
 * Linking an external identity to the signed-in user (Account settings → Linked accounts): the
 * link starts from the console, the provider's callback stages the identity, and only the user
 * who started the link confirms it. Unlinking and the list of linkable providers.
 */
class OAuthAccountLinkTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private const FRONTEND = 'https://console.example.test';

    private MockHandler $mock;

    private User $jane;

    private AuthProvider $github;

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
        $this->jane = $this->userWithRole('developer', ['username' => 'jane', 'email' => 'jane@corp.test']);

        $this->mock = new MockHandler;
        config(['authprovider.guzzle' => ['handler' => HandlerStack::create($this->mock)]]);

        $this->github = AuthProvider::query()->create([
            'name' => 'GitHub', 'driver' => 'github', 'client_id' => 'gh-client', 'client_secret' => 'gh-secret',
            'is_enabled' => true, 'auto_register' => false,
        ]);
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    /** GitHub answers the code exchange with identity $id. */
    private function queueGithub(int $id, string $email): void
    {
        foreach ([
            ['access_token' => 'gho_token', 'token_type' => 'bearer', 'scope' => 'user:email'],
            ['id' => $id, 'node_id' => 'MDQ6VXNlcj'.$id, 'login' => 'jane-gh', 'name' => 'Jane GH', 'avatar_url' => 'https://avatars.example.test/'.$id],
            [['email' => $email, 'primary' => true, 'verified' => true]],
        ] as $body) {
            $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body)));
        }
    }

    /** Start a link as $user and come back from GitHub as identity $id. */
    private function linkCallback(User $user, int $id, string $email = 'jane.personal@example.test'): TestResponse
    {
        $url = $this->as($user)->getJson('/api/oauth/github/link')->assertOk()->json('redirect_url');
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('gh-client', $query['client_id']);

        $this->queueGithub($id, $email);
        $this->app['auth']->forgetGuards();

        return $this->get('/oauth/github/callback?'.http_build_query(['code' => 'code-1', 'state' => $query['state']]));
    }

    private function linkCode(TestResponse $callback): string
    {
        $location = (string) $callback->headers->get('Location');
        $this->assertStringStartsWith(self::FRONTEND.'/account?sso_link=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['sso_link'];
    }

    public function test_starting_a_link_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/oauth/github/link')->assertUnauthorized();
        $this->postJson('/api/oauth/link/complete', ['code' => 'x'])->assertUnauthorized();
    }

    public function test_a_user_links_an_identity_with_another_address_and_signs_in_with_it(): void
    {
        $code = $this->linkCode($this->linkCallback($this->jane, 777));
        $this->assertSame(0, SocialAccount::count(), 'the callback alone links nothing');

        $this->as($this->jane)->postJson('/api/oauth/link/complete', ['code' => $code])
            ->assertCreated()
            ->assertJsonPath('message', __('api.oauth.account_linked'))
            ->assertJsonPath('account.provider', 'github')
            ->assertJsonPath('account.provider_user_id', '777')
            ->assertJsonPath('account.email', 'jane.personal@example.test')
            ->assertJsonMissingPath('account.access_token');

        $account = SocialAccount::query()->sole();
        $this->assertSame((string) $this->jane->id, (string) $account->user_id);
        $this->assertSame('gho_token', $account->access_token);
        $this->assertSame('social_account.link', AuditEvent::query()->where('action', 'social_account.link')->sole()->action);

        $this->as($this->jane)->getJson('/api/social-accounts')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.provider', 'github');

        // The code works once
        $this->as($this->jane)->postJson('/api/oauth/link/complete', ['code' => $code])
            ->assertStatus(422)->assertJsonPath('message', __('api.oauth.link_invalid'));

        // Signing in with the identity reaches Jane, although its address is not hers
        $this->app['auth']->forgetGuards();
        $url = $this->getJson('/api/oauth/github/redirect')->assertOk()->json('redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->queueGithub(777, 'jane.personal@example.test');
        $signIn = $this->get('/oauth/github/callback?'.http_build_query(['code' => 'code-2', 'state' => $query['state']]));
        $this->assertStringStartsWith(self::FRONTEND.'/oauth/callback?code=', (string) $signIn->headers->get('Location'));
        parse_str((string) parse_url((string) $signIn->headers->get('Location'), PHP_URL_QUERY), $handoff);
        $this->postJson('/api/oauth/exchange', ['code' => $handoff['code']])->assertOk()->assertJsonPath('user_id', (string) $this->jane->id);
    }

    public function test_only_the_user_who_started_the_link_can_confirm_it(): void
    {
        $mallory = $this->userWithRole('developer', ['username' => 'mallory', 'email' => 'mallory@corp.test']);

        // Mallory started the link; Jane's browser completed the provider's sign-in
        $code = $this->linkCode($this->linkCallback($mallory, 777));

        $this->as($this->jane)->postJson('/api/oauth/link/complete', ['code' => $code])
            ->assertStatus(422)->assertJsonPath('message', __('api.oauth.link_invalid'));
        $this->assertSame(0, SocialAccount::count());

        // Spent: Mallory cannot use it afterwards either
        $this->as($mallory)->postJson('/api/oauth/link/complete', ['code' => $code])->assertStatus(422);
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_an_identity_linked_to_another_user_is_refused(): void
    {
        $other = User::factory()->create(['email' => 'other@corp.test']);
        SocialAccount::query()->create([
            'user_id' => $other->id, 'provider' => 'github', 'provider_user_id' => '777',
            'email' => 'other@example.test', 'access_token' => '',
        ]);

        $callback = $this->linkCallback($this->jane, 777);

        $callback->assertRedirect(self::FRONTEND.'/account?'.http_build_query(['sso_link_error' => __('api.oauth.identity_taken')]));
        $this->assertSame((string) $other->id, (string) SocialAccount::query()->sole()->user_id);
    }

    public function test_a_link_taken_meanwhile_is_a_conflict_and_a_repeated_link_is_fine(): void
    {
        $code = $this->linkCode($this->linkCallback($this->jane, 777));
        $other = User::factory()->create(['email' => 'other@corp.test']);
        SocialAccount::query()->create([
            'user_id' => $other->id, 'provider' => 'github', 'provider_user_id' => '777',
            'email' => 'other@example.test', 'access_token' => '',
        ]);

        $this->as($this->jane)->postJson('/api/oauth/link/complete', ['code' => $code])
            ->assertStatus(409)->assertJsonPath('message', __('api.oauth.identity_taken'));

        $mine = $this->linkCode($this->linkCallback($this->jane, 888));
        $this->as($this->jane)->postJson('/api/oauth/link/complete', ['code' => $mine])->assertCreated();
        $again = $this->linkCode($this->linkCallback($this->jane, 888));
        $this->as($this->jane)->postJson('/api/oauth/link/complete', ['code' => $again])
            ->assertOk()->assertJsonPath('message', __('api.oauth.already_linked'));
    }

    public function test_a_link_names_one_of_several_providers(): void
    {
        $oidc = AuthProvider::query()->create([
            'name' => 'Corporate IdP', 'driver' => 'oidc', 'client_id' => 'runr-console',
            'client_secret' => 'secret-of-the-test-client-0123456789abcdef',
            'oidc_discovery_url' => OidcTestIdp::DISCOVERY_URL, 'is_enabled' => true,
        ]);
        $idp = new OidcTestIdp;
        $idp->fake();
        $idp->claims = ['sub' => 'corp-42', 'email' => 'jane@corp-idp.test'];

        $url = $this->as($this->jane)->getJson('/api/oauth/oidc/link?provider='.$oidc->id)->assertOk()->json('redirect_url');
        $state = $idp->authorize($url)['state'];
        $this->app['auth']->forgetGuards();
        $code = $this->linkCode($this->get('/oauth/oidc/callback?'.http_build_query(['code' => OidcTestIdp::CODE, 'state' => $state])));

        $this->as($this->jane)->postJson('/api/oauth/link/complete', ['code' => $code])
            ->assertCreated()
            ->assertJsonPath('account.provider_user_id', $oidc->id.':corp-42')
            ->assertJsonPath('account.auth_provider_id', $oidc->id)
            ->assertJsonPath('account.provider_name', 'Corporate IdP');

        $this->as($this->jane)->getJson('/api/oauth/oidc/link?provider=01UNKNOWNPROVIDER000000000')->assertNotFound();
    }

    public function test_users_unlink_their_own_accounts_only(): void
    {
        $mine = SocialAccount::query()->create([
            'user_id' => $this->jane->id, 'provider' => 'github', 'provider_user_id' => '777',
            'email' => 'jane@example.test', 'access_token' => '',
        ]);
        $other = User::factory()->create(['email' => 'other@corp.test']);
        $theirs = SocialAccount::query()->create([
            'user_id' => $other->id, 'provider' => 'github', 'provider_user_id' => '888',
            'email' => 'other@example.test', 'access_token' => '',
        ]);

        $this->as($this->jane)->deleteJson("/api/social-accounts/{$theirs->id}")->assertForbidden();
        $this->as($this->jane)->deleteJson("/api/social-accounts/{$mine->id}")->assertOk();

        $this->assertNull($mine->fresh());
        $this->assertNotNull($theirs->fresh());
    }

    public function test_linkable_providers_are_the_enabled_oauth_and_oidc_ones(): void
    {
        AuthProvider::query()->create(['name' => 'Off', 'driver' => 'google', 'client_id' => 'x', 'client_secret' => 'y', 'is_enabled' => false]);
        AuthProvider::query()->create(['name' => 'Corporate SSO', 'driver' => 'saml', 'client_id' => '', 'client_secret' => '', 'is_enabled' => true]);
        AuthProvider::query()->create(['name' => 'Corporate IdP', 'driver' => 'oidc', 'client_id' => 'x', 'client_secret' => 'y', 'oidc_discovery_url' => OidcTestIdp::DISCOVERY_URL, 'is_enabled' => true]);

        $this->as($this->jane)->getJson('/api/social-accounts/providers')
            ->assertOk()
            ->assertExactJson([
                ['id' => AuthProvider::where('name', 'Corporate IdP')->value('id'), 'name' => 'Corporate IdP', 'driver' => 'oidc'],
                ['id' => $this->github->id, 'name' => 'GitHub', 'driver' => 'github'],
            ]);
    }
}
