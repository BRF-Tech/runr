<?php

namespace Tests\Feature\AuthProvider;

use App\Models\User;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Models\SocialAccount;
use Modules\AuthProvider\Models\SsoGroupMapping;
use Modules\AuthProvider\Providers\AuthProviderServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsWithRoles;
use Tests\Support\OidcTestIdp;
use Tests\TestCase;

/**
 * Generic OpenID Connect sign-in (driver `oidc`) against a fake identity provider: discovery,
 * authorization code flow with PKCE, state and nonce, ID token verification (signature via JWKS,
 * iss, aud, exp, nonce), userinfo claims, e-mail verification and group mapping.
 */
class OidcSignInTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private const FRONTEND = 'https://console.example.test';

    private OidcTestIdp $idp;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://runr.example.test',
            'app.frontend_url' => self::FRONTEND,
            'authprovider.enabled' => true,
        ]);
        // The suite runs with AUTH_PROVIDER_ENABLED off: boot the module like SamlSsoTest does
        $this->app->register(AuthProviderServiceProvider::class, true);
        Artisan::call('migrate', ['--path' => 'Modules/AuthProvider/Database/Migrations', '--force' => true]);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
        Log::spy();

        $this->owner = $this->userWithRole('owner', ['username' => 'admin', 'email' => 'admin@example.test']);
        $this->idp = new OidcTestIdp;
        $this->idp->fake();
    }

    private function provider(array $attributes = []): AuthProvider
    {
        return AuthProvider::query()->create(array_merge([
            'name' => 'Corporate IdP',
            'driver' => 'oidc',
            'client_id' => $this->idp->clientId,
            'client_secret' => $this->idp->clientSecret,
            'oidc_discovery_url' => OidcTestIdp::DISCOVERY_URL,
            'is_enabled' => true,
            'auto_register' => true,
        ], $attributes));
    }

    /** Start a sign-in, let the IdP read the request, and return the authorization URL's query. */
    private function startSignIn(?AuthProvider $provider = null): array
    {
        $url = $this->getJson('/api/oauth/oidc/redirect'.($provider ? '?provider='.$provider->id : ''))
            ->assertOk()->json('redirect_url');

        return $this->idp->authorize($url);
    }

    private function providerCallback(string $state, array $extra = []): TestResponse
    {
        return $this->get('/oauth/oidc/callback?'.http_build_query(['code' => OidcTestIdp::CODE, 'state' => $state] + $extra));
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

    // ── Authorization request ────────────────────────────────────────────────

    public function test_the_authorization_request_carries_pkce_state_and_nonce(): void
    {
        $this->provider(['scopes' => ['groups']]);

        $url = $this->getJson('/api/oauth/oidc/redirect')->assertOk()->json('redirect_url');
        $this->assertStringStartsWith(OidcTestIdp::ISSUER.'/authorize?', $url);

        $query = $this->idp->authorize($url);
        $this->assertSame('runr-console', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(url('/oauth/oidc/callback'), $query['redirect_uri']);
        $this->assertSame(['openid', 'email', 'profile', 'groups'], explode(' ', $query['scope']));
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['code_challenge']);
        $this->assertNotEmpty($query['nonce']);
        $this->assertNotEmpty($query['state']);
        // Nothing of the flow travels in clear text
        $this->assertStringNotContainsString($query['nonce'], $query['state']);
    }

    public function test_an_issuer_url_works_as_discovery_url(): void
    {
        $this->provider(['oidc_discovery_url' => OidcTestIdp::ISSUER]);
        $this->idp->claims = ['email' => 'jane@example.test'];

        $this->assertSignedIn($this->providerCallback($this->startSignIn()['state']));
    }

    public function test_a_discovery_document_of_another_issuer_is_refused(): void
    {
        $this->provider(['oidc_issuer' => 'https://other-idp.example.test']);

        $this->getJson('/api/oauth/oidc/redirect')
            ->assertStatus(502)
            ->assertJsonPath('message', __('api.oauth.provider_unavailable'));
    }

    // ── Callback ─────────────────────────────────────────────────────────────

    public function test_a_verified_id_token_signs_the_user_in(): void
    {
        $provider = $this->provider();
        $this->idp->claims = ['email' => 'jane@example.test', 'email_verified' => true, 'name' => 'Jane Doe'];

        $state = $this->startSignIn()['state'];
        $response = $this->providerCallback($state);

        $this->assertSignedIn($response);
        $user = User::where('email', 'jane@example.test')->firstOrFail();
        $this->assertSame('Jane Doe', $user->name);

        $account = SocialAccount::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('oidc', $account->provider);
        $this->assertSame($provider->id.':user-123', $account->provider_user_id, 'an OIDC subject is scoped to its provider');
        $this->assertSame(OidcTestIdp::ACCESS_TOKEN, $account->access_token);

        // The token request proved the PKCE verifier and authenticated the client
        $token = $this->idp->tokenRequests[0]->data();
        $this->assertSame('authorization_code', $token['grant_type']);
        $this->assertSame(url('/oauth/oidc/callback'), $token['redirect_uri']);
        $this->assertNotEmpty($token['code_verifier']);

        // The handoff code gives the console its session
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->postJson('/api/oauth/exchange', ['code' => $query['code']])->assertOk()->assertJsonPath('user_id', (string) $user->id);
    }

    public function test_the_next_sign_in_finds_the_linked_account(): void
    {
        $this->provider();
        $this->idp->claims = ['email' => 'jane@example.test'];
        $this->assertSignedIn($this->providerCallback($this->startSignIn()['state']));

        // The address changed at the IdP: the subject still finds the account
        $this->idp->claims = ['email' => 'jane.doe@example.test'];
        $this->assertSignedIn($this->providerCallback($this->startSignIn()['state']));

        $this->assertSame(1, User::where('email', 'like', 'jane%')->count());
        $this->assertSame(1, SocialAccount::count());
        $this->assertSame('jane.doe@example.test', SocialAccount::first()->email);
    }

    public function test_the_state_works_once(): void
    {
        $this->provider();
        $this->idp->claims = ['email' => 'jane@example.test'];
        $state = $this->startSignIn()['state'];

        $this->assertSignedIn($this->providerCallback($state));
        $this->assertRefused($this->providerCallback($state), __('api.oauth.invalid_state'));
        $this->assertRefused($this->providerCallback('not-a-state'), __('api.oauth.invalid_state'));
    }

    public function test_a_state_of_another_driver_is_refused(): void
    {
        $this->provider();
        $state = $this->startSignIn()['state'];

        $this->assertRefused(
            $this->get('/oauth/github/callback?'.http_build_query(['code' => OidcTestIdp::CODE, 'state' => $state])),
            __('api.oauth.invalid_state'),
        );
    }

    public function test_a_cancelled_sign_in_says_so(): void
    {
        $this->provider();
        $state = $this->startSignIn()['state'];

        $this->assertRefused(
            $this->get('/oauth/oidc/callback?'.http_build_query(['error' => 'access_denied', 'state' => $state])),
            __('api.oauth.denied'),
        );
        $this->assertSame([], $this->idp->tokenRequests);
    }

    public function test_a_wrong_pkce_verifier_fails_at_the_token_endpoint(): void
    {
        $this->provider();
        $this->idp->claims = ['email' => 'jane@example.test'];
        $state = $this->startSignIn()['state'];
        $this->idp->codeChallenge = 'a-challenge-runr-did-not-send';

        $this->assertRefused($this->providerCallback($state), __('api.oauth.provider_error'));
        $this->assertNull(User::where('email', 'jane@example.test')->first());
    }

    public static function forgedTokens(): array
    {
        return [
            'another issuer' => [fn (array $c) => ['iss' => 'https://evil.example.test'] + $c, 'issuer'],
            'another audience' => [fn (array $c) => ['aud' => 'someone-else'] + $c, 'audience'],
            'several audiences without azp' => [fn (array $c) => ['aud' => ['runr-console', 'someone-else']] + $c, 'authorized party'],
            'azp of another client' => [fn (array $c) => ['azp' => 'someone-else'] + $c, 'authorized party'],
            'expired' => [fn (array $c) => ['exp' => time() - 3600, 'iat' => time() - 7200] + $c, 'Expired'],
            'issued in the future' => [fn (array $c) => ['iat' => time() + 3600] + $c, 'prior to'],
            'no expiry' => [fn (array $c) => array_diff_key($c, ['exp' => 1]), 'no expiry'],
            'another nonce' => [fn (array $c) => ['nonce' => 'replayed-nonce'] + $c, 'nonce'],
            'no nonce' => [fn (array $c) => array_diff_key($c, ['nonce' => 1]), 'nonce'],
        ];
    }

    #[DataProvider('forgedTokens')]
    public function test_an_id_token_that_fails_a_check_is_refused(\Closure $tamper, string $reason): void
    {
        $this->provider();
        $this->idp->claims = ['email' => 'jane@example.test'];
        $this->idp->tamper = $tamper;

        $this->assertRefused($this->providerCallback($this->startSignIn()['state']), __('api.oauth.provider_error'));

        $this->assertNull(User::where('email', 'jane@example.test')->first());
        $this->assertSame(0, SocialAccount::count());
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => $message === 'OAuth sign-in failed' && str_contains((string) ($context['reason'] ?? ''), $reason)
        );
    }

    public static function foreignSignatures(): array
    {
        return [
            'the published kid, another key' => ['key-1', 'Signature verification failed'],
            'a kid the IdP does not publish' => ['key-unknown', 'no signing key with kid key-unknown'],
        ];
    }

    #[DataProvider('foreignSignatures')]
    public function test_an_id_token_not_signed_with_a_published_key_is_refused(string $kid, string $reason): void
    {
        $this->provider();
        $this->idp->claims = ['email' => 'jane@example.test'];
        $this->idp->signingKid = $kid;
        $this->idp->signingKey = OidcTestIdp::strangerKey();

        $this->assertRefused($this->providerCallback($this->startSignIn()['state']), __('api.oauth.provider_error'));

        $this->assertNull(User::where('email', 'jane@example.test')->first());
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => $message === 'OAuth sign-in failed' && str_contains((string) ($context['reason'] ?? ''), $reason)
        );
    }

    public function test_rotated_signing_keys_are_fetched_again(): void
    {
        $this->provider();
        $this->idp->claims = ['email' => 'jane@example.test'];
        $this->assertSignedIn($this->providerCallback($this->startSignIn()['state']));
        $this->assertSame(1, $this->idp->jwksFetches);

        // The IdP rotates to a new key: the cached set does not know its kid
        $this->idp->published = ['key-2'];
        $this->idp->signingKid = 'key-2';
        $this->assertSignedIn($this->providerCallback($this->startSignIn()['state']));
        $this->assertSame(2, $this->idp->jwksFetches);
    }

    public function test_client_secret_basic_when_the_idp_offers_no_post(): void
    {
        $this->provider();
        $this->idp->authMethods = ['client_secret_basic'];
        $this->idp->claims = ['email' => 'jane@example.test'];

        $this->assertSignedIn($this->providerCallback($this->startSignIn()['state']));

        $request = $this->idp->tokenRequests[0];
        $this->assertArrayNotHasKey('client_secret', $request->data());
        $this->assertStringStartsWith('Basic ', $request->header('Authorization')[0]);
    }

    // ── Claims ───────────────────────────────────────────────────────────────

    public function test_an_unverified_address_does_not_reach_an_existing_account(): void
    {
        $this->provider();
        $this->idp->claims = ['email' => 'admin@example.test', 'email_verified' => false];

        $this->assertRefused($this->providerCallback($this->startSignIn()['state']), __('api.oauth.email_unverified'));
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_userinfo_completes_the_id_token_and_must_be_about_the_same_subject(): void
    {
        $this->provider();
        $this->idp->claims = ['email' => 'jane@example.test'];
        $this->idp->userinfo = ['given_name' => 'Jane', 'family_name' => 'Roe', 'picture' => 'https://idp.example.test/jane.png'];

        $this->assertSignedIn($this->providerCallback($this->startSignIn()['state']));
        $this->assertSame('Jane Roe', User::where('email', 'jane@example.test')->value('name'));
        $this->assertSame('https://idp.example.test/jane.png', SocialAccount::first()->avatar_url);

        $this->idp->userinfo = ['sub' => 'somebody-else'];
        $this->assertRefused($this->providerCallback($this->startSignIn()['state']), __('api.oauth.provider_error'));
    }

    public function test_the_groups_claim_feeds_sso_group_mapping(): void
    {
        $provider = $this->provider(['groups_attribute' => 'roles']);
        SsoGroupMapping::query()->create(['auth_provider_id' => $provider->id, 'external_group' => 'runr-admins', 'role' => 'admin']);
        $this->idp->claims = ['email' => 'jane@example.test'];
        $this->idp->userinfo = ['roles' => ['runr-admins', 'staff']];

        $this->assertSignedIn($this->providerCallback($this->startSignIn()['state']));

        $this->flushRbac();
        $jane = User::where('email', 'jane@example.test')->firstOrFail();
        $this->assertSame('admin', (new SingleTenantOrganization)->memberRole($jane->id));
    }

    // ── Several OIDC providers ───────────────────────────────────────────────

    public function test_one_of_several_oidc_providers_is_chosen_by_id(): void
    {
        $first = $this->provider(['name' => 'A IdP', 'client_id' => 'client-a']);
        $second = $this->provider(['name' => 'B IdP']);
        $this->idp->claims = ['email' => 'jane@example.test'];

        // Without an ID the first in sign-in order (name) answers
        $this->assertSame('client-a', $this->startSignIn()['client_id']);

        $query = $this->startSignIn($second);
        $this->assertSame('runr-console', $query['client_id']);
        $this->assertSignedIn($this->providerCallback($query['state']));
        $this->assertSame($second->id.':user-123', SocialAccount::first()->provider_user_id);

        $this->getJson('/api/oauth/oidc/redirect?provider=01UNKNOWNPROVIDER000000000')->assertNotFound();
        $first->update(['is_enabled' => false]);
        $this->getJson('/api/oauth/oidc/redirect?provider='.$first->id)->assertNotFound();
    }

    public function test_a_provider_disabled_during_the_sign_in_is_refused_at_the_callback(): void
    {
        $provider = $this->provider();
        $state = $this->startSignIn()['state'];
        $provider->update(['is_enabled' => false]);

        $this->assertRefused($this->providerCallback($state), __('api.oauth.provider_not_found'));
    }
}
