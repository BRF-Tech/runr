<?php

namespace Tests\Feature\Captcha;

use App\Models\CaptchaSetting;
use App\Models\Notification;
use App\Models\User;
use App\Services\Captcha\CaptchaConfig;
use App\Services\Captcha\CaptchaGate;
use App\Services\Captcha\CaptchaNotifier;
use App\Services\Captcha\LocalChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Tests\Feature\Captcha\Concerns\CaptchaHelpers;
use Tests\Feature\WhiteLabel\Concerns\WhiteLabelTenancy;
use Tests\TestCase;

/**
 * Multi-tenant: a wrong configuration of the bot protection (captcha-turnstile-plan §3.5) — who is
 * told, and whose fallback opens.
 *
 *   an organization's own keys   its members whose role grants settings.manage get the alarm, in
 *                                its schema; its fallback window is its own — never the platform's
 *   the platform's protection    the platform administrators get it, never an organization
 *
 * Every piece of state is the scope's: one scope's misconfiguration or outage never opens the
 * other's window, and a local answer of one scope never passes in another.
 */
class TenantCaptchaAlarmTest extends TestCase
{
    use CaptchaHelpers;
    use RefreshDatabase;
    use WhiteLabelTenancy;

    private Tenant $tenant;

    private User $owner;

    private User $developer;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWhiteLabelTenancy();
        $this->setUpCaptcha();
        // A store every cache manager shares: tenant context (CacheTenancyBootstrapper) builds a new
        // manager, whose "array" store would start empty — Redis in production is one store
        config(['captcha.cache_store' => 'file']);
        Cache::store('file')->flush();

        $this->useTenancyResolvers();

        // the white-label schema plus the tenant's own users, notifications and their preferences
        $migrations = array_merge(self::WL_TENANT_MIGRATIONS, [
            'Modules/Tenancy/Database/TenantMigrations/0003_create_users_table.php',
            'Modules/Tenancy/Database/TenantMigrations/0007_add_user_id_to_notifications.php',
            'Modules/Tenancy/Database/TenantMigrations/0015_create_notification_preferences_table.php',
        ]);
        sort($migrations);
        $this->tenant = $this->createTenantWithSchema('acme', $migrations);

        $this->platformAdmin = $this->admin();
        $this->owner = $this->member($this->tenant, 'owner');
        $this->developer = $this->member($this->tenant, 'developer');
        // jane is acme's member (without settings.manage), mallory is nobody's
        $jane = $this->makeUser(['username' => 'jane', 'email' => 'jane@acme.test']);
        TenantUser::create(['tenant_id' => $this->tenant->id, 'user_id' => $jane->id, 'role' => 'developer']);
        $this->makeUser(['username' => 'mallory', 'email' => 'mallory@example.test']);
    }

    protected function tearDown(): void
    {
        $this->forgetTenancyResolvers();
        Cache::store('file')->flush();
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function login(array $extra = [])
    {
        return $this->postJson('/api/auth/login', ['login' => 'jane', 'password' => 'password'] + $extra);
    }

    /** @return list<array{user_id: string, reason: ?string, level: string, type: string}> */
    private function tenantAlarms(): array
    {
        return $this->tenant->run(fn () => $this->alarms());
    }

    /** @return list<array{user_id: string, reason: ?string, level: string, type: string}> */
    private function alarms(): array
    {
        return Notification::query()->orderBy('id')->get()
            ->filter(fn (Notification $n) => ($n->data['captcha'] ?? null) === 'misconfigured')
            ->map(fn (Notification $n) => ['user_id' => (string) $n->user_id, 'reason' => $n->data['reason'] ?? null, 'level' => $n->level, 'type' => $n->type])
            ->values()->all();
    }

    public function test_an_organizations_refused_secret_falls_back_on_its_own_forms_and_tells_its_settings_managers(): void
    {
        $this->turnstileInstall();
        $this->actingAs($this->owner)->putJson('/api/tenant/captcha', [
            'provider' => 'turnstile', 'site_key' => 'acme-site', 'secret' => 'acme-wrong-secret',
        ], $this->tenantHeaders($this->tenant))->assertOk()->assertJsonPath('problem', null);

        // the provider refuses the organization's secret, takes the platform's
        Http::fake([self::TS_HOST => fn (HttpRequest $r) => $r['secret'] === 'acme-wrong-secret'
            ? Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']])
            : Http::response(['success' => true])]);

        $first = $this->login(['tenant_slug' => 'acme', 'captcha_token' => 'tok-1'])->assertStatus(409)->assertJsonPath('captcha', 'fallback');
        $second = $this->login(['tenant_slug' => 'acme', 'captcha_token' => 'tok-2'])->assertStatus(409);

        // the organization's window, not the platform's
        $this->assertTrue((bool) Cache::store('file')->get(CaptchaGate::FALLBACK_FLAG.':tenant:'.$this->tenant->id));
        $this->assertFalse((bool) Cache::store('file')->get(CaptchaGate::FALLBACK_FLAG));
        $this->login(['tenant_slug' => 'acme', LocalChallenge::FIELD => $this->solve($first->json('challenge'))])->assertOk();
        $this->login([LocalChallenge::FIELD => $this->solve($second->json('challenge'))])
            ->assertStatus(422)->assertJsonPath('captcha', 'fallback_inactive');
        // the platform's forms keep the provider
        $this->login(['captcha_token' => 'tok-platform'])->assertOk();

        // told once, in the organization: its owner (settings.manage), not its developer, not the platform
        $this->assertSame([[
            'user_id' => (string) $this->owner->id,
            'reason' => CaptchaConfig::SECRET_REJECTED,
            'level' => 'error',
            'type' => CaptchaNotifier::TYPE,
        ]], $this->tenantAlarms());
        $this->assertSame([], $this->alarms(), 'nothing in the central context');

        // the organization's settings page shows it, the platform's does not
        $this->actingAs($this->owner)->getJson('/api/tenant/captcha', $this->tenantHeaders($this->tenant))->assertOk()
            ->assertJsonPath('problem.reason', CaptchaConfig::SECRET_REJECTED);
        $this->actingAs($this->platformAdmin)->getJson('/api/admin/captcha')->assertOk()->assertJsonPath('problem', null);
    }

    public function test_the_platforms_wrong_configuration_tells_the_platform_administrators(): void
    {
        $this->installCaptcha(['provider' => 'turnstile', 'site_key' => self::TS_SITE_KEY, 'secret' => '', 'forms' => '']);
        Http::fake();

        // an organization without keys of its own uses the platform's protection
        $challenge = $this->login(['tenant_slug' => 'acme', 'captcha_token' => 'tok'])->assertStatus(409)->json('challenge');
        Http::assertNothingSent();
        $this->login(['tenant_slug' => 'acme', LocalChallenge::FIELD => $this->solve($challenge)])->assertOk();

        $this->assertSame([[
            'user_id' => (string) $this->platformAdmin->id,
            'reason' => CaptchaConfig::SECRET_MISSING,
            'level' => 'error',
            'type' => CaptchaNotifier::TYPE,
        ]], $this->alarms());
        $this->assertSame([], $this->tenantAlarms());

        $this->actingAs($this->platformAdmin)->getJson('/api/admin/captcha')->assertOk()->assertJsonPath('problem.reason', CaptchaConfig::SECRET_MISSING);
        $this->actingAs($this->owner)->getJson('/api/tenant/captcha', $this->tenantHeaders($this->tenant))->assertOk()->assertJsonPath('problem', null);
    }

    // ── Every piece of state is the scope's ─────────────────────────────────

    private function platformLogin(array $extra = [])
    {
        return $this->postJson('/api/auth/login', ['login' => 'mallory', 'password' => 'password'] + $extra);
    }

    private function flag(?string $tenantId = null): bool
    {
        return (bool) Cache::store('file')->get($tenantId === null ? CaptchaGate::FALLBACK_FLAG : CaptchaGate::FALLBACK_FLAG.':tenant:'.$tenantId);
    }

    /** acme's own keys, written as the API stores them */
    private function acmeKeys(string $secret = 'acme-secret'): CaptchaSetting
    {
        return CaptchaSetting::query()->create(['tenant_id' => (string) $this->tenant->id, 'provider' => 'turnstile', 'site_key' => 'acme-site', 'secret' => $secret]);
    }

    public function test_an_organizations_misconfiguration_leaves_the_platform_on_the_widget(): void
    {
        $this->turnstileInstall();
        $row = $this->acmeKeys();
        // acme's stored secret no longer decrypts
        DB::table('captcha_settings')->where('id', $row->id)->update(['secret' => 'eyJpdiI6ImJyb2tlbiJ9']);
        Http::fake([self::TS_HOST => Http::response(['success' => true])]);

        $this->getJson('/api/captcha/config?tenant=acme')->assertJsonPath('misconfigured', true);
        $this->getJson('/api/captcha/config')->assertJsonPath('misconfigured', false)->assertJsonPath('site_key', self::TS_SITE_KEY);

        // acme's member gets acme's fallback
        $this->login(['tenant_slug' => 'acme'])->assertStatus(409)->assertJsonPath('captcha', 'fallback');
        $this->assertTrue($this->flag((string) $this->tenant->id));

        // the platform's forms stay on the provider, its window shut, its status clean
        $this->assertFalse($this->flag());
        $this->platformLogin()->assertStatus(422)->assertJsonPath('captcha', 'required');
        $this->platformLogin(['captcha_token' => 'tok-platform'])->assertOk();
        Http::assertSentCount(1);
        $this->assertFalse($this->flag());
        // a non-member naming acme is checked like its member — acme's fallback, never the platform's
        $this->platformLogin(['tenant_slug' => 'acme'])->assertStatus(409)->assertJsonPath('captcha', 'fallback');
        $this->assertFalse($this->flag());
        $this->actingAs($this->platformAdmin)->getJson('/api/admin/captcha')->assertJsonPath('problem', null)->assertJsonPath('fallback_active', false);
        $this->actingAs($this->owner)->getJson('/api/tenant/captcha', $this->tenantHeaders($this->tenant))
            ->assertJsonPath('problem.reason', CaptchaConfig::SECRET_UNREADABLE);
    }

    public function test_a_platform_outage_leaves_an_organization_with_healthy_keys_alone(): void
    {
        $this->turnstileInstall();
        $this->acmeKeys();
        Http::fake([self::TS_HOST => fn (HttpRequest $r) => $r['secret'] === 'acme-secret'
            ? Http::response(['success' => true])
            : Http::response('', 503)]);

        $platform = $this->platformLogin(['captcha_token' => 'tok-p'])->assertStatus(409);
        $this->assertTrue($this->flag());

        // acme's member signs in with acme's keys; acme's window stays shut
        $this->login(['tenant_slug' => 'acme', 'captcha_token' => 'tok-a'])->assertOk();
        $this->assertFalse($this->flag((string) $this->tenant->id));
        $this->login(['tenant_slug' => 'acme', LocalChallenge::FIELD => $this->solve($platform->json('challenge'))])
            ->assertStatus(422)->assertJsonPath('captcha', 'fallback_inactive');
        $this->assertSame([], $this->tenantAlarms());
    }

    public function test_a_local_answer_of_one_scope_never_passes_in_another(): void
    {
        $this->turnstileInstall();
        $this->acmeKeys();
        Http::fake([self::TS_HOST => Http::response('', 503)]);

        // both providers down: both windows open
        $platform = $this->solve($this->platformLogin(['captcha_token' => 'tok-p'])->assertStatus(409)->json('challenge'));
        $acme = $this->solve($this->login(['tenant_slug' => 'acme', 'captcha_token' => 'tok-a'])->assertStatus(409)->json('challenge'));
        $this->assertTrue($this->flag());
        $this->assertTrue($this->flag((string) $this->tenant->id));

        $this->login(['tenant_slug' => 'acme', LocalChallenge::FIELD => $platform])->assertStatus(422)->assertJsonPath('captcha', 'bad_signature');
        $this->platformLogin([LocalChallenge::FIELD => $acme])->assertStatus(422)->assertJsonPath('captcha', 'bad_signature');

        // in their own scope they pass, once
        $this->login(['tenant_slug' => 'acme', LocalChallenge::FIELD => $acme])->assertOk();
        $this->platformLogin([LocalChallenge::FIELD => $platform])->assertOk();
        $this->platformLogin([LocalChallenge::FIELD => $platform])->assertStatus(422)->assertJsonPath('captcha', 'replayed');
    }

    public function test_a_spent_token_is_spent_in_its_own_scope(): void
    {
        $this->turnstileInstall();
        $this->acmeKeys();
        Http::fake([self::TS_HOST => Http::response(['success' => true])]);

        $this->login(['tenant_slug' => 'acme', 'captcha_token' => 'tok-1'])->assertOk();
        $this->login(['tenant_slug' => 'acme', 'captcha_token' => 'tok-1'])->assertStatus(422)->assertJsonPath('captcha', 'invalid');
        Http::assertSentCount(1);

        // the platform's scope keeps its own record: the provider decides there (a token of acme's
        // widget fails the platform's secret in real life)
        $this->platformLogin(['captcha_token' => 'tok-1'])->assertOk();
        Http::assertSentCount(2);
    }

    public function test_the_challenge_endpoint_hands_out_the_pages_scope(): void
    {
        $this->turnstileInstall();
        $row = $this->acmeKeys();
        DB::table('captcha_settings')->where('id', $row->id)->update(['secret' => 'eyJpdiI6ImJyb2tlbiJ9']);
        Http::fake([self::TS_HOST => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        // acme's sign-in page: acme's scope (its keys are broken: a check at once)
        $challenge = $this->postJson('/api/captcha/local/challenge', ['form' => 'login', 'tenant' => 'acme'])->assertOk()->json('challenge');
        $this->login(['tenant_slug' => 'acme', LocalChallenge::FIELD => $this->solve($challenge)])->assertOk();

        // a non-member with an answer of acme's scope: checked like a member, then a wrong password
        $other = $this->postJson('/api/captcha/local/challenge', ['form' => 'login', 'tenant' => 'acme'])->assertOk()->json('challenge');
        $this->platformLogin(['tenant_slug' => 'acme', LocalChallenge::FIELD => $this->solve($other)])
            ->assertStatus(401)->assertExactJson(['message' => __('api.auth.login_failed')]);
        Http::assertNothingSent();

        // a form an organization's keys never guard gets the platform's scope (healthy: 409 provider)
        $this->postJson('/api/captcha/local/challenge', ['form' => 'register', 'tenant' => 'acme'])->assertStatus(409)->assertJsonPath('captcha', 'provider');
        $this->assertFalse($this->flag());
    }
}
