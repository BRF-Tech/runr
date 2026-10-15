<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Models\SocialAccount;
use Modules\AuthProvider\Providers\AuthProviderServiceProvider;
use Modules\AuthProvider\Services\Saml\SamlService;
use Tests\Support\SamlTestIdp;
use Tests\TestCase;

/**
 * SAML 2.0 SP-initiated sign-in — provider config validation, SP metadata,
 * AuthnRequest (HTTP-Redirect, optionally signed), ACS (HTTP-POST) with signed-response
 * validation, and user provisioning through the same path as the OAuth callback.
 */
class SamlSsoTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND = 'https://console.example.test';

    private SamlTestIdp $idp;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://runr.example.test',
            'app.frontend_url' => self::FRONTEND,
            'authprovider.enabled' => true,
        ]);

        // The suite runs with AUTH_PROVIDER_ENABLED off: register + boot the module the way it
        // boots when enabled (config merge, routes).
        $this->app->register(AuthProviderServiceProvider::class, true);
        Artisan::call('migrate', ['--path' => 'Modules/AuthProvider/Database/Migrations', '--force' => true]);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();

        $this->idp = new SamlTestIdp;
        Log::spy();
        $this->admin = User::factory()->create(['username' => 'admin']);
    }

    private function createProvider(array $overrides = []): AuthProvider
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/auth-providers', array_merge([
            'name' => 'Corporate SSO',
            'driver' => 'saml',
            'saml_idp_entity_id' => SamlTestIdp::ENTITY_ID,
            'saml_idp_sso_url' => SamlTestIdp::SSO_URL,
            'saml_idp_x509_cert' => $this->idp->certificate,
            'auto_register' => true,
        ], $overrides))->assertCreated();

        $this->app['auth']->forgetGuards();

        return AuthProvider::findOrFail($response->json('id'));
    }

    private function sp(AuthProvider $provider): array
    {
        return app(SamlService::class)->spUrls($provider);
    }

    /** Start a sign-in and return the AuthnRequest ID the IdP must answer. */
    private function startLogin(AuthProvider $provider): string
    {
        $url = $this->getJson("/api/saml/{$provider->id}/login")->assertOk()->json('redirect_url');

        return $this->authnRequest($url)->documentElement->getAttribute('ID');
    }

    private function authnRequest(string $redirectUrl): DOMDocument
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $query);
        $doc = new DOMDocument;
        $doc->loadXML(gzinflate(base64_decode($query['SAMLRequest'])));

        return $doc;
    }

    private function postResponse(AuthProvider $provider, string $samlResponse)
    {
        return $this->post("/api/saml/{$provider->id}/acs", ['SAMLResponse' => $samlResponse, 'RelayState' => self::FRONTEND]);
    }

    private function validResponse(AuthProvider $provider, string $requestId, array $overrides = []): string
    {
        $sp = $this->sp($provider);

        return $this->idp->response(array_merge([
            'in_response_to' => $requestId,
            'destination' => $sp['acs_url'],
            'audience' => $sp['entity_id'],
            'name_id' => 'u-7f3a91',
            'attributes' => ['mail' => 'jane@example.test', 'displayName' => 'Jane Doe'],
        ], $overrides));
    }

    /** @param string|null $reason expected fragment of the logged rejection reason */
    private function assertRejected($response, ?string $reason = null): void
    {
        $response->assertRedirect();
        if ($reason !== null) {
            Log::shouldHaveReceived('warning')->withArgs(
                fn ($message, $context = []) => $message === 'SAML response rejected'
                    && str_contains((string) ($context['reason'] ?? ''), $reason)
            );
        }
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(self::FRONTEND.'/login?oauth_error=', $location);
        $this->assertSame(0, SocialAccount::count(), 'a rejected response must not link an account');
        $this->assertNull(User::where('email', 'jane@example.test')->first(), 'a rejected response must not provision a user');
    }

    // ── Configuration ────────────────────────────────────────────────────────

    public function test_saml_config_requires_idp_fields(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/auth-providers', ['name' => 'SSO', 'driver' => 'saml'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['saml_idp_entity_id', 'saml_idp_sso_url', 'saml_idp_x509_cert'])
            ->assertJsonMissingValidationErrors(['client_id', 'client_secret']);
    }

    public function test_idp_certificate_must_be_a_valid_x509_certificate(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/auth-providers', [
            'name' => 'SSO',
            'driver' => 'saml',
            'saml_idp_entity_id' => SamlTestIdp::ENTITY_ID,
            'saml_idp_sso_url' => SamlTestIdp::SSO_URL,
            'saml_idp_x509_cert' => "-----BEGIN CERTIFICATE-----\nbm90IGEgY2VydGlmaWNhdGU=\n-----END CERTIFICATE-----",
        ])->assertStatus(422)->assertJsonValidationErrors(['saml_idp_x509_cert']);
    }

    public function test_request_signing_needs_a_matching_sp_certificate_and_key(): void
    {
        [$spCert, $spKey] = SamlTestIdp::keyPair('sp.example.test');
        [, $otherKey] = SamlTestIdp::keyPair('other.example.test');
        $base = [
            'name' => 'SSO',
            'driver' => 'saml',
            'saml_idp_entity_id' => SamlTestIdp::ENTITY_ID,
            'saml_idp_sso_url' => SamlTestIdp::SSO_URL,
            'saml_idp_x509_cert' => $this->idp->certificate,
            'saml_sign_requests' => true,
        ];

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/auth-providers', $base)
            ->assertStatus(422)->assertJsonValidationErrors(['saml_sp_x509_cert', 'saml_sp_private_key']);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/auth-providers', $base + [
            'saml_sp_x509_cert' => $spCert, 'saml_sp_private_key' => 'not a key',
        ])->assertStatus(422)->assertJsonValidationErrors(['saml_sp_private_key']);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/auth-providers', $base + [
            'saml_sp_x509_cert' => $spCert, 'saml_sp_private_key' => $otherKey,
        ])->assertStatus(422)->assertJsonPath('errors.saml_sp_private_key.0', __('api.saml.key_mismatch'));

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/auth-providers', $base + [
            'saml_sp_x509_cert' => $spCert, 'saml_sp_private_key' => $spKey,
        ])->assertCreated();
    }

    public function test_provider_is_stored_with_normalized_certificate_encrypted_key_and_sp_endpoints(): void
    {
        [$spCert, $spKey] = SamlTestIdp::keyPair('sp.example.test');

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/auth-providers', [
            'name' => 'Corporate SSO',
            'driver' => 'saml',
            'saml_idp_entity_id' => SamlTestIdp::ENTITY_ID,
            'saml_idp_sso_url' => SamlTestIdp::SSO_URL,
            'saml_idp_x509_cert' => $this->idp->certificateBody(), // bare base64, as copied from metadata
            'saml_sign_requests' => true,
            'saml_sp_x509_cert' => $spCert,
            'saml_sp_private_key' => $spKey,
        ])->assertCreated();

        $id = $response->json('id');
        $response->assertJsonPath('has_saml_sp_private_key', true)
            ->assertJsonPath('saml_sp.entity_id', "https://runr.example.test/api/saml/{$id}/metadata")
            ->assertJsonPath('saml_sp.acs_url', "https://runr.example.test/api/saml/{$id}/acs")
            ->assertJsonPath('saml_sp.metadata_url', "https://runr.example.test/api/saml/{$id}/metadata")
            ->assertJsonMissingPath('saml_sp_private_key');

        $provider = AuthProvider::findOrFail($id);
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $provider->saml_idp_x509_cert);
        $this->assertNotFalse(openssl_x509_read($provider->saml_idp_x509_cert));
        $this->assertTrue(openssl_x509_check_private_key($spCert, $provider->saml_sp_private_key));

        $raw = DB::table('auth_providers')->where('id', $id)->value('saml_sp_private_key');
        $this->assertStringNotContainsString('PRIVATE KEY', $raw, 'SP private key must be stored encrypted');

        // Listing never returns the key either; an update without a key keeps it.
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/auth-providers')
            ->assertOk()->assertJsonMissingPath('0.saml_sp_private_key')->assertJsonPath('0.has_saml_sp_private_key', true);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/auth-providers/{$id}", ['saml_email_attribute' => 'email', 'saml_sp_private_key' => ''])
            ->assertOk();
        $this->assertTrue(openssl_x509_check_private_key($spCert, $provider->fresh()->saml_sp_private_key));

        // Tenant installs: the SP endpoints carry the tenant.
        $this->assertSame(
            "https://runr.example.test/api/saml/{$id}/acs?tenant=acme",
            app(SamlService::class)->spUrls($provider, 'acme')['acs_url'],
        );
    }

    public function test_saml_is_not_served_by_the_oauth_endpoints(): void
    {
        $this->getJson('/api/oauth/saml/redirect')->assertNotFound();
    }

    // ── SP metadata & AuthnRequest ───────────────────────────────────────────

    public function test_metadata_describes_the_sp(): void
    {
        [$spCert, $spKey] = SamlTestIdp::keyPair('sp.example.test');
        $provider = $this->createProvider([
            'saml_sign_requests' => true, 'saml_sp_x509_cert' => $spCert, 'saml_sp_private_key' => $spKey,
        ]);
        $sp = $this->sp($provider);

        $response = $this->get("/api/saml/{$provider->id}/metadata")->assertOk();
        $this->assertStringStartsWith('application/samlmetadata+xml', $response->headers->get('Content-Type'));

        $doc = new DOMDocument;
        $doc->loadXML($response->getContent());
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('md', 'urn:oasis:names:tc:SAML:2.0:metadata');

        $this->assertSame($sp['entity_id'], $doc->documentElement->getAttribute('entityID'));
        $acs = $xp->query('//md:SPSSODescriptor/md:AssertionConsumerService')->item(0);
        $this->assertSame($sp['acs_url'], $acs->getAttribute('Location'));
        $this->assertSame('urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST', $acs->getAttribute('Binding'));

        $descriptor = $xp->query('//md:SPSSODescriptor')->item(0);
        $this->assertSame('true', $descriptor->getAttribute('AuthnRequestsSigned'));
        $this->assertSame('true', $descriptor->getAttribute('WantAssertionsSigned'));
        $this->assertSame(1, $xp->query('//md:SPSSODescriptor/md:KeyDescriptor[@use="signing"]')->length);

        $this->get('/api/saml/01zzzzzzzzzzzzzzzzzzzzzzzz/metadata')->assertNotFound();
    }

    public function test_login_redirects_to_the_idp_with_a_signed_authn_request(): void
    {
        [$spCert, $spKey] = SamlTestIdp::keyPair('sp.example.test');
        $provider = $this->createProvider([
            'saml_sign_requests' => true, 'saml_sp_x509_cert' => $spCert, 'saml_sp_private_key' => $spKey,
        ]);
        $sp = $this->sp($provider);

        $url = $this->getJson("/api/saml/{$provider->id}/login")->assertOk()->json('redirect_url');
        $this->assertStringStartsWith(SamlTestIdp::SSO_URL.'?', $url);

        $request = $this->authnRequest($url)->documentElement;
        $this->assertSame('AuthnRequest', $request->localName);
        $this->assertSame(SamlTestIdp::SSO_URL, $request->getAttribute('Destination'));
        $this->assertSame($sp['acs_url'], $request->getAttribute('AssertionConsumerServiceURL'));
        $this->assertSame($sp['entity_id'], $request->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer')->item(0)->textContent);

        // HTTP-Redirect binding signature over the raw query parameters, verifiable with the SP cert.
        $raw = [];
        foreach (explode('&', (string) parse_url($url, PHP_URL_QUERY)) as $pair) {
            [$k, $v] = explode('=', $pair, 2);
            $raw[$k] = $v;
        }
        $signed = "SAMLRequest={$raw['SAMLRequest']}&RelayState={$raw['RelayState']}&SigAlg={$raw['SigAlg']}";
        $this->assertSame(1, openssl_verify($signed, base64_decode(urldecode($raw['Signature'])), $spCert, OPENSSL_ALGO_SHA256));

        // Plain browser navigation gets a redirect instead of JSON.
        $this->get("/api/saml/{$provider->id}/login")->assertRedirect();
    }

    public function test_login_for_a_disabled_provider_is_refused(): void
    {
        $provider = $this->createProvider(['is_enabled' => false]);

        $this->getJson("/api/saml/{$provider->id}/login")->assertNotFound();
    }

    // ── ACS ──────────────────────────────────────────────────────────────────

    public function test_signed_response_signs_the_user_in_through_the_shared_oauth_path(): void
    {
        $provider = $this->createProvider();
        $requestId = $this->startLogin($provider);

        $response = $this->postResponse($provider, $this->validResponse($provider, $requestId));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(self::FRONTEND.'/oauth/callback?code=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $user = User::where('email', 'jane@example.test')->firstOrFail();
        $this->assertSame('Jane Doe', $user->name);
        $this->assertSame('jane', $user->username);
        // One-time code in the URL, the token only through POST /api/oauth/exchange
        $this->assertArrayNotHasKey('token', $query);
        $handoff = $this->postJson('/api/oauth/exchange', ['code' => $query['code']])->assertOk();
        $this->assertSame((string) $user->id, (string) $handoff->json('user_id'));
        $this->assertSame((string) $user->id, (string) PersonalAccessToken::findToken($handoff->json('token'))?->tokenable_id);
        $this->postJson('/api/oauth/exchange', ['code' => $query['code']])->assertUnprocessable();

        $account = SocialAccount::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('saml', $account->provider);
        $this->assertSame("{$provider->id}:u-7f3a91", $account->provider_user_id);

        // Next sign-in resolves the same account through the social link.
        $again = $this->postResponse($provider, $this->validResponse($provider, $this->startLogin($provider)));
        $this->assertStringStartsWith(self::FRONTEND.'/oauth/callback?code=', $again->headers->get('Location'));
        $this->assertSame(1, User::where('email', 'jane@example.test')->count());
        $this->assertSame(1, SocialAccount::count());
    }

    public function test_existing_user_is_linked_by_email(): void
    {
        $existing = User::factory()->create(['username' => 'jdoe', 'email' => 'jane@example.test']);
        $provider = $this->createProvider(['auto_register' => false]);
        $requestId = $this->startLogin($provider);

        $response = $this->postResponse($provider, $this->validResponse($provider, $requestId));

        $this->assertStringStartsWith(self::FRONTEND.'/oauth/callback?code=', $response->headers->get('Location'));
        $this->assertSame(1, User::where('email', 'jane@example.test')->count());
        $this->assertSame((string) $existing->id, (string) SocialAccount::firstOrFail()->user_id);
    }

    public function test_unknown_user_is_refused_without_auto_register(): void
    {
        $provider = $this->createProvider(['auto_register' => false]);
        $requestId = $this->startLogin($provider);

        $this->assertRejected($this->postResponse($provider, $this->validResponse($provider, $requestId)));
    }

    public function test_attribute_mapping_is_configurable(): void
    {
        $provider = $this->createProvider(['saml_email_attribute' => 'NameID', 'saml_name_attribute' => 'urn:example:full-name']);
        $requestId = $this->startLogin($provider);

        $this->postResponse($provider, $this->validResponse($provider, $requestId, [
            'name_id' => 'john@example.test',
            'name_id_format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            'attributes' => ['mail' => 'other@example.test', 'urn:example:full-name' => 'John Roe'],
        ]))->assertRedirect();

        $user = User::where('email', 'john@example.test')->firstOrFail();
        $this->assertSame('John Roe', $user->name);
        $this->assertNull(User::where('email', 'other@example.test')->first());
    }

    public function test_unsigned_response_is_rejected(): void
    {
        $provider = $this->createProvider();
        $requestId = $this->startLogin($provider);

        $this->assertRejected($this->postResponse($provider, $this->validResponse($provider, $requestId, ['sign' => false])), 'not signed');
    }

    public function test_response_signed_by_another_key_is_rejected(): void
    {
        [$cert, $key] = SamlTestIdp::keyPair('attacker.example.test');
        $provider = $this->createProvider();
        $requestId = $this->startLogin($provider);

        $this->assertRejected($this->postResponse($provider, $this->validResponse($provider, $requestId, [
            'signing_key' => $key, 'signing_cert' => $cert,
        ])), 'Signature validation failed');
    }

    public function test_expired_response_is_rejected(): void
    {
        $provider = $this->createProvider();
        $requestId = $this->startLogin($provider);

        $this->assertRejected($this->postResponse($provider, $this->validResponse($provider, $requestId, [
            'issued_at' => time() - 1800, 'not_on_or_after' => time() - 900,
        ])), 'expired');
    }

    public function test_wrong_audience_is_rejected(): void
    {
        $provider = $this->createProvider();
        $requestId = $this->startLogin($provider);

        $this->assertRejected($this->postResponse($provider, $this->validResponse($provider, $requestId, [
            'audience' => 'https://some-other-sp.example.test/metadata',
        ])), 'Invalid audience');
    }

    public function test_wrong_destination_is_rejected(): void
    {
        $provider = $this->createProvider();
        $requestId = $this->startLogin($provider);

        $this->assertRejected($this->postResponse($provider, $this->validResponse($provider, $requestId, [
            'destination' => 'https://some-other-sp.example.test/acs',
            'recipient' => 'https://some-other-sp.example.test/acs',
        ])), 'The response was received at');
    }

    public function test_wrong_issuer_is_rejected(): void
    {
        $provider = $this->createProvider();
        $requestId = $this->startLogin($provider);

        $this->assertRejected($this->postResponse($provider, $this->validResponse($provider, $requestId, [
            'issuer' => 'https://evil-idp.example.test',
        ])), 'Invalid issuer');
    }

    public function test_unsolicited_response_is_rejected(): void
    {
        $provider = $this->createProvider();
        $this->startLogin($provider);

        $this->assertRejected($this->postResponse($provider, $this->validResponse($provider, '_not-issued-by-us')), 'no pending AuthnRequest');
        $this->assertRejected($this->postResponse($provider, $this->validResponse($provider, '_x', ['in_response_to' => null])), 'InResponseTo=none');
    }

    public function test_a_response_cannot_be_replayed(): void
    {
        $provider = $this->createProvider();
        $samlResponse = $this->validResponse($provider, $this->startLogin($provider));

        $first = $this->postResponse($provider, $samlResponse);
        $this->assertStringStartsWith(self::FRONTEND.'/oauth/callback?code=', $first->headers->get('Location'));

        $second = $this->postResponse($provider, $samlResponse);
        $this->assertStringStartsWith(self::FRONTEND.'/login?oauth_error=', $second->headers->get('Location'));
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => str_contains((string) ($context['reason'] ?? ''), 'no pending AuthnRequest')
        );
    }

    public function test_request_ids_are_bound_to_their_provider(): void
    {
        $providerA = $this->createProvider(['name' => 'A']);
        $providerB = $this->createProvider(['name' => 'B']);
        $requestId = $this->startLogin($providerA);

        // Answer A's request, but post it to B's ACS (valid signature/audience for B).
        $this->assertRejected($this->postResponse($providerB, $this->validResponse($providerB, $requestId)), 'no pending AuthnRequest');
    }
}
