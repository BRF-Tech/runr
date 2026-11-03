<?php

namespace Tests\Feature\Captcha;

use App\Models\CaptchaSetting;
use App\Services\Captcha\CaptchaVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantInvitation;
use Modules\Tenancy\Models\TenantUser;
use Tests\Feature\Captcha\Concerns\CaptchaHelpers;
use Tests\Feature\WhiteLabel\Concerns\WhiteLabelTenancy;
use Tests\TestCase;

/**
 * Multi-tenant bot protection (docs/security.md#bot-protection): an organization's own provider
 * and keys (for its white-label host names) win over the platform's, which win over the install
 * default, which wins over off — on the organization's own surfaces only (contract §3.5): its
 * sign-in page (?tenant=<slug>), its members' sign-ins (tenant_slug) and its invitations. A
 * sign-in naming it is checked with its keys whoever signs in; for a non-member, a platform
 * administrator who is not one, or an unknown account it then fails exactly like a member's wrong
 * password. It cannot switch the protection off; its secret is write-only; the install's
 * protection stays with the platform administrators.
 */
class TenantCaptchaTest extends TestCase
{
    use CaptchaHelpers;
    use RefreshDatabase;
    use WhiteLabelTenancy;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWhiteLabelTenancy();
        $this->setUpCaptcha();

        $this->useTenancyResolvers();

        $this->tenant = $this->createTenantWithSchema('acme', self::WL_TENANT_MIGRATIONS);
    }

    protected function tearDown(): void
    {
        $this->forgetTenancyResolvers();
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_precedence_tenant_over_platform_over_install_over_off(): void
    {
        $owner = $this->member($this->tenant, 'owner');
        $headers = $this->tenantHeaders($this->tenant);

        // off
        $this->getJson('/api/captcha/config?tenant=acme')->assertJsonPath('provider', 'none');

        // install
        $this->turnstileInstall();
        $this->getJson('/api/captcha/config?tenant=acme')->assertJsonPath('provider', 'turnstile')->assertJsonPath('site_key', self::TS_SITE_KEY);

        // platform
        $this->actingAs($this->admin())->putJson('/api/admin/captcha', ['provider' => 'hcaptcha', 'site_key' => 'platform-site', 'secret' => 'platform-secret'])->assertOk();
        $this->getJson('/api/captcha/config?tenant=acme')->assertJsonPath('provider', 'hcaptcha')->assertJsonPath('site_key', 'platform-site');

        // the organization's own keys
        $this->actingAs($owner)->putJson('/api/tenant/captcha', [
            'provider' => 'turnstile', 'site_key' => 'acme-site', 'secret' => 'acme-secret',
        ], $headers)->assertOk()
            ->assertJsonPath('provider', 'turnstile')
            ->assertJsonPath('site_key', 'acme-site')
            ->assertJsonPath('secret_set', true)
            ->assertJsonPath('effective.source', 'tenant')
            ->assertJsonPath('platform.provider', 'hcaptcha')
            ->assertJsonMissingPath('secret');

        $this->getJson('/api/captcha/config?tenant=acme')->assertExactJson([
            'provider' => 'turnstile', 'site_key' => 'acme-site', 'fallback' => 'local',
            'forms' => ['login', 'register', 'password_reset', 'demo_login', 'public_forms'],
            'misconfigured' => false,
        ]);
        // the platform's sign-in page keeps the platform's
        $this->getJson('/api/captcha/config')->assertJsonPath('provider', 'hcaptcha')->assertJsonPath('site_key', 'platform-site');
        $this->getJson('/api/captcha/config?tenant=unknown')->assertJsonPath('site_key', 'platform-site');

        // back to the platform's
        $this->actingAs($owner)->putJson('/api/tenant/captcha', ['provider' => null], $headers)->assertOk()
            ->assertJsonPath('provider', null)
            ->assertJsonPath('effective.source', 'platform');
        $this->getJson('/api/captcha/config?tenant=acme')->assertJsonPath('site_key', 'platform-site');
        $this->assertNull(CaptchaSetting::forTenant((string) $this->tenant->id));
    }

    public function test_a_members_sign_in_is_verified_with_the_organizations_secret(): void
    {
        $owner = $this->member($this->tenant, 'owner');
        $jane = $this->makeUser(['username' => 'jane', 'email' => 'jane@acme.test']);
        TenantUser::create(['tenant_id' => $this->tenant->id, 'user_id' => $jane->id, 'role' => 'developer']);
        $this->turnstileInstall();
        $this->actingAs($owner)->putJson('/api/tenant/captcha', [
            'provider' => 'turnstile', 'site_key' => 'acme-site', 'secret' => 'acme-secret',
        ], $this->tenantHeaders($this->tenant))->assertOk();
        Http::fake([self::TS_HOST => Http::response(['success' => true])]);

        $this->postJson('/api/auth/login', ['login' => 'jane', 'password' => 'password', 'tenant_slug' => 'acme', 'captcha_token' => 'tok-acme'])->assertOk();
        $this->postJson('/api/auth/login', ['login' => 'JANE@acme.test', 'password' => 'password', 'tenant_slug' => 'acme', 'captcha_token' => 'tok-acme-2'])->assertOk();
        $this->postJson('/api/auth/login', ['login' => 'jane', 'password' => 'password', 'captcha_token' => 'tok-platform'])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === CaptchaVerifier::ENDPOINTS['turnstile'] && $r['response'] === 'tok-acme' && $r['secret'] === 'acme-secret');
        Http::assertSent(fn (HttpRequest $r) => $r['response'] === 'tok-acme-2' && $r['secret'] === 'acme-secret');
        Http::assertSent(fn (HttpRequest $r) => $r['response'] === 'tok-platform' && $r['secret'] === self::TS_SECRET_PASS);
    }

    /** acme uses Cloudflare's always-pass test keys; the platform's are real ones */
    private function acmeAlwaysPasses(): void
    {
        $owner = $this->member($this->tenant, 'owner');
        $this->installCaptcha(['provider' => 'turnstile', 'site_key' => 'platform-site', 'secret' => 'platform-secret', 'forms' => '']);
        $this->actingAs($owner)->putJson('/api/tenant/captcha', [
            'provider' => 'turnstile', 'site_key' => self::TS_SITE_KEY, 'secret' => self::TS_SECRET_PASS,
        ], $this->tenantHeaders($this->tenant))->assertOk();
        // the provider's test secret passes every token; the platform's refuses acme's
        Http::fake([self::TS_HOST => fn (HttpRequest $r) => $r['secret'] === self::TS_SECRET_PASS
            ? Http::response(['success' => true])
            : Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
    }

    private function signIn(string $login, string $password, string $slug = 'acme', ?string $token = null)
    {
        static $n = 0;

        return $this->postJson('/api/auth/login', [
            'login' => $login, 'password' => $password, 'tenant_slug' => $slug, 'captcha_token' => $token ?? 'tok-'.(++$n),
        ]);
    }

    public function test_before_the_password_a_member_and_anyone_else_get_the_same_answer(): void
    {
        $this->acmeAlwaysPasses();
        $jane = $this->makeUser(['username' => 'jane', 'email' => 'jane@acme.test']);
        TenantUser::create(['tenant_id' => $this->tenant->id, 'user_id' => $jane->id, 'role' => 'developer']);
        $this->makeUser(['username' => 'mallory', 'email' => 'mallory@example.test']);
        $admin = $this->admin();

        // the page renders acme's widget, for everyone
        $this->getJson('/api/captcha/config?tenant=acme')->assertJsonPath('site_key', self::TS_SITE_KEY);

        // a member's wrong password, a non-member's any password (a platform administrator's too),
        // an account that does not exist: one answer
        $answers = [
            'member, wrong password' => $this->signIn('jane', 'wrong-password'),
            'non-member, wrong password' => $this->signIn('mallory', 'wrong-password'),
            'non-member, right password' => $this->signIn('mallory', 'password'),
            'administrator, right password' => $this->signIn($admin->username, 'password'),
            'no such account' => $this->signIn('nobody', 'password'),
        ];
        foreach ($answers as $case => $response) {
            $response->assertStatus(401, $case);
            $this->assertSame(['message' => __('api.auth.login_failed')], $response->json(), $case);
        }

        // every one of them was checked with acme's keys, none with the platform's
        Http::assertSentCount(count($answers));
        Http::assertNotSent(fn (HttpRequest $r) => $r['secret'] === 'platform-secret');
        $this->assertSame(0, DB::table('personal_access_tokens')->count(), 'nobody got a token');

        // the member's right password signs in
        $this->signIn('jane', 'password')->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_a_non_member_never_reaches_the_two_factor_step(): void
    {
        $this->acmeAlwaysPasses();
        $mallory = $this->makeUser(['username' => 'mallory', 'email' => 'mallory@example.test']);
        $mallory->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        // neither the 202 of the password step nor a recovery code opens a way in
        $this->signIn('mallory', 'password', token: 'tok-2fa')->assertStatus(401)->assertExactJson(['message' => __('api.auth.login_failed')]);
        $this->postJson('/api/auth/login', [
            'login' => 'mallory', 'password' => 'password', 'tenant_slug' => 'acme', 'captcha_token' => 'tok-2fa-b', 'two_factor_code' => 'recovery-one',
        ])->assertStatus(401)->assertExactJson(['message' => __('api.auth.login_failed')]);
        // and the follow-up of an earlier answer does not exist for it either
        $this->postJson('/api/auth/login', [
            'login' => 'mallory', 'password' => 'password', 'tenant_slug' => 'acme', 'captcha_token' => 'tok-2fa', 'two_factor_code' => 'recovery-one',
        ])->assertStatus(422)->assertJsonPath('captcha', 'invalid');
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_a_bad_token_of_the_organization_gets_the_same_422_for_everyone(): void
    {
        $owner = $this->member($this->tenant, 'owner');
        $jane = $this->makeUser(['username' => 'jane', 'email' => 'jane@acme.test']);
        TenantUser::create(['tenant_id' => $this->tenant->id, 'user_id' => $jane->id, 'role' => 'developer']);
        $this->makeUser(['username' => 'mallory', 'email' => 'mallory@example.test']);
        $this->turnstileInstall();
        $this->actingAs($owner)->putJson('/api/tenant/captcha', [
            'provider' => 'turnstile', 'site_key' => 'acme-site', 'secret' => 'acme-secret',
        ], $this->tenantHeaders($this->tenant))->assertOk();
        Http::fake([self::TS_HOST => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $answers = [
            $this->signIn('jane', 'password'),
            $this->signIn('mallory', 'password'),
            $this->signIn('nobody', 'password'),
        ];
        foreach ($answers as $response) {
            $response->assertStatus(422);
        }
        $this->assertSame($answers[0]->json(), $answers[1]->json());
        $this->assertSame($answers[0]->json(), $answers[2]->json());
        $this->assertSame('invalid', $answers[0]->json('captcha'));
        // all checked with acme's secret
        Http::assertSentCount(3);
        Http::assertNotSent(fn (HttpRequest $r) => $r['secret'] !== 'acme-secret');
    }

    public function test_the_slug_of_another_organization_does_not_help(): void
    {
        $globex = $this->plainTenant('globex');
        CaptchaSetting::query()->create(['tenant_id' => (string) $globex->id, 'provider' => 'turnstile', 'site_key' => self::TS_SITE_KEY, 'secret' => self::TS_SECRET_PASS]);
        $jane = $this->makeUser(['username' => 'jane', 'email' => 'jane@acme.test']);
        TenantUser::create(['tenant_id' => $this->tenant->id, 'user_id' => $jane->id, 'role' => 'developer']);
        $this->installCaptcha(['provider' => 'turnstile', 'site_key' => 'platform-site', 'secret' => 'platform-secret', 'forms' => '']);
        Http::fake([self::TS_HOST => fn (HttpRequest $r) => $r['secret'] === self::TS_SECRET_PASS
            ? Http::response(['success' => true])
            : Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        // jane is acme's member, not globex's: globex's always-pass keys check her, then the sign-in
        // fails like a wrong password, her right one notwithstanding
        $this->signIn('jane', 'password', 'globex', 'tok-g')->assertStatus(401)->assertExactJson(['message' => __('api.auth.login_failed')]);
        Http::assertSent(fn (HttpRequest $r) => $r['response'] === 'tok-g' && $r['secret'] === self::TS_SECRET_PASS);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_an_invitation_of_the_organization_uses_its_keys(): void
    {
        Route::middleware('api')->prefix('api')->group(base_path('Modules/Tenancy/routes/public.php'));
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();

        $owner = $this->member($this->tenant, 'owner');
        $this->makeUser(['username' => 'bob', 'email' => 'bob@example.test', 'password' => Hash::make('bob-password')]);
        $this->installCaptcha(['provider' => 'turnstile', 'site_key' => 'platform-site', 'secret' => 'platform-secret', 'forms' => '']);
        $this->actingAs($owner)->putJson('/api/tenant/captcha', [
            'provider' => 'turnstile', 'site_key' => 'acme-site', 'secret' => 'acme-secret',
        ], $this->tenantHeaders($this->tenant))->assertOk();
        Http::fake([self::TS_HOST => fn (HttpRequest $r) => $r['secret'] === 'acme-secret'
            ? Http::response(['success' => true])
            : Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
        [$plain, $hash] = TenantInvitation::newToken();
        TenantInvitation::create(['tenant_id' => $this->tenant->id, 'email' => 'bob@example.test', 'role' => 'developer', 'token' => $hash, 'expires_at' => now()->addDays(7)]);

        $this->postJson("/api/invitations/{$plain}/accept", ['password' => 'bob-password', 'captcha_token' => 'tok-inv'])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r['response'] === 'tok-inv' && $r['secret'] === 'acme-secret');
        $this->assertSame(2, TenantUser::query()->where('tenant_id', $this->tenant->id)->count(), 'the owner and bob');
    }

    public function test_an_organization_cannot_switch_the_protection_off_or_pick_forms(): void
    {
        $owner = $this->member($this->tenant, 'owner');
        $headers = $this->tenantHeaders($this->tenant);

        $this->actingAs($owner)->putJson('/api/tenant/captcha', ['provider' => 'none'], $headers)->assertStatus(422)->assertJsonValidationErrors('provider');
        $this->actingAs($owner)->putJson('/api/tenant/captcha', ['provider' => 'turnstile'], $headers)->assertStatus(422)->assertJsonValidationErrors(['site_key', 'secret']);

        // forms are not a tenant field: ignored
        $this->actingAs($owner)->putJson('/api/tenant/captcha', [
            'provider' => 'recaptcha', 'site_key' => 'g', 'secret' => 's', 'forms' => [],
        ], $headers)->assertOk()->assertJsonPath('effective.forms', ['login', 'register', 'password_reset', 'demo_login', 'public_forms']);
    }

    public function test_the_tenants_secret_is_write_only(): void
    {
        $owner = $this->member($this->tenant, 'owner');
        $headers = $this->tenantHeaders($this->tenant);
        $plain = 'acme-secret-'.bin2hex(random_bytes(6));

        $put = $this->actingAs($owner)->putJson('/api/tenant/captcha', ['provider' => 'turnstile', 'site_key' => 'k', 'secret' => $plain], $headers)->assertOk();
        $get = $this->actingAs($owner)->getJson('/api/tenant/captcha', $headers)->assertOk()->assertJsonPath('secret_set', true);

        foreach ([$put, $get, $this->getJson('/api/captcha/config?tenant=acme')] as $response) {
            $this->assertStringNotContainsString($plain, $response->getContent());
        }
        $this->assertNotSame($plain, DB::table('captcha_settings')->where('tenant_id', (string) $this->tenant->id)->value('secret'));
        $this->assertStringNotContainsString($plain, json_encode(DB::table('audit_events')->get()->all()));

        // a site key change keeps the secret
        $this->actingAs($owner)->putJson('/api/tenant/captcha', ['site_key' => 'k2'], $headers)->assertOk()->assertJsonPath('secret_set', true);
    }

    public function test_members_and_owners_stay_out_of_what_is_not_theirs(): void
    {
        $member = $this->member($this->tenant, 'member');
        $owner = $this->member($this->tenant, 'owner');

        $this->actingAs($member)->putJson('/api/tenant/captcha', ['provider' => 'turnstile', 'site_key' => 'k', 'secret' => 's'], $this->tenantHeaders($this->tenant))
            ->assertForbidden();

        // settings.manage in an organization is never the install's protection
        $this->actingAs($owner)->putJson('/api/admin/captcha', ['provider' => 'none'], $this->tenantHeaders($this->tenant))->assertForbidden();
        $this->actingAs($owner)->getJson('/api/admin/captcha', $this->tenantHeaders($this->tenant))->assertForbidden();
        $this->actingAs($owner)->get('/api/admin/captcha/metrics', $this->tenantHeaders($this->tenant))->assertForbidden();
        $this->actingAs($this->admin())->getJson('/api/admin/captcha')->assertOk()->assertJsonPath('multi_tenant', true);
        $this->actingAs($this->admin())->get('/api/admin/captcha/metrics')->assertOk();
    }
}
