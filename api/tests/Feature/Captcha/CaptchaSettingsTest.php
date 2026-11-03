<?php

namespace Tests\Feature\Captcha;

use App\Models\CaptchaSetting;
use App\Models\SecureSetting;
use App\Services\Captcha\CaptchaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Captcha\Concerns\CaptchaHelpers;
use Tests\Feature\WhiteLabel\Concerns\CoreWhiteLabel;
use Tests\TestCase;

/**
 * Bot protection settings of the install (docs/security.md#bot-protection): off by default; the
 * stored platform value wins over the install default (Helm values captcha.* → env) per field;
 * the secret is write-only — encrypted at rest, never in an answer; the sign-up CAPTCHA of
 * earlier releases carries over (env and stored settings) on the sign-up form only.
 */
class CaptchaSettingsTest extends TestCase
{
    use CaptchaHelpers;
    use CoreWhiteLabel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoreWhiteLabel();
        $this->setUpCaptcha();
    }

    public function test_precedence_platform_over_install_over_off(): void
    {
        $admin = $this->coreUser(true);

        // off
        $this->getJson('/api/captcha/config')->assertJsonPath('provider', 'none');
        $this->actingAs($admin)->getJson('/api/admin/captcha')->assertOk()
            ->assertJsonPath('provider', 'none')
            ->assertJsonPath('source', 'off')
            ->assertJsonPath('stored', null);

        // install default (env)
        $this->turnstileInstall(forms: 'login,register');
        $this->getJson('/api/captcha/config')->assertExactJson([
            'provider' => 'turnstile', 'site_key' => self::TS_SITE_KEY, 'fallback' => 'local', 'forms' => ['login', 'register'],
            'misconfigured' => false,
        ]);
        $this->actingAs($admin)->getJson('/api/admin/captcha')->assertJsonPath('source', 'install')->assertJsonPath('secret_set', true);

        // the platform's stored values win, per field
        $this->actingAs($admin)->putJson('/api/admin/captcha', [
            'provider' => 'recaptcha',
            'site_key' => 'g-site-key',
            'secret' => 'g-secret-value',
            'forms' => ['login', 'demo_login'],
        ])->assertOk()
            ->assertJsonPath('provider', 'recaptcha')
            ->assertJsonPath('source', 'platform')
            ->assertJsonPath('stored.provider', 'recaptcha')
            ->assertJsonPath('defaults.provider', 'turnstile')
            ->assertJsonPath('fallback', 'local')
            ->assertJsonPath('verify_timeout_ms', 4000);
        $this->getJson('/api/captcha/config')->assertExactJson([
            'provider' => 'recaptcha', 'site_key' => 'g-site-key', 'fallback' => 'local', 'forms' => ['login', 'demo_login'],
            'misconfigured' => false,
        ]);

        // a field sent as null goes back to the install value
        $this->actingAs($admin)->putJson('/api/admin/captcha', ['forms' => null])->assertOk()->assertJsonPath('forms', ['login', 'register']);

        // an explicit empty list guards nothing
        $this->actingAs($admin)->putJson('/api/admin/captcha', ['forms' => []])->assertOk()->assertJsonPath('forms', []);
        $this->getJson('/api/captcha/config')->assertJsonPath('forms', []);

        // reset: the install default again
        $this->actingAs($admin)->putJson('/api/admin/captcha', ['reset' => true])->assertOk()
            ->assertJsonPath('provider', 'turnstile')->assertJsonPath('stored', null);
        $this->assertSame(0, CaptchaSetting::query()->count());

        // the platform switches it off over the install default
        $this->actingAs($admin)->putJson('/api/admin/captcha', ['provider' => 'none'])->assertOk()->assertJsonPath('source', 'off');
        $this->getJson('/api/captcha/config')->assertJsonPath('provider', 'none')->assertJsonPath('site_key', null);
    }

    public function test_the_secret_is_write_only_and_encrypted_at_rest(): void
    {
        $admin = $this->coreUser(true);
        $plain = 'top-secret-'.bin2hex(random_bytes(6));

        $put = $this->actingAs($admin)->putJson('/api/admin/captcha', [
            'provider' => 'turnstile', 'site_key' => self::TS_SITE_KEY, 'secret' => $plain,
        ])->assertOk()->assertJsonPath('secret_set', true)->assertJsonMissingPath('secret')->assertJsonMissingPath('stored.secret');
        $this->assertStringNotContainsString($plain, $put->getContent());

        $get = $this->actingAs($admin)->getJson('/api/admin/captcha')->assertOk();
        $this->assertStringNotContainsString($plain, $get->getContent());
        $this->assertStringNotContainsString($plain, $this->getJson('/api/captcha/config')->getContent());

        $raw = DB::table('captcha_settings')->whereNull('tenant_id')->value('secret');
        $this->assertNotSame($plain, $raw);
        $this->assertStringNotContainsString($plain, (string) $raw);
        $this->assertSame($plain, CaptchaSetting::platform()->secretValue());
        $this->assertArrayNotHasKey('secret', CaptchaSetting::platform()->toArray());

        // null / omitted keep it, secret_clear removes it
        $this->actingAs($admin)->putJson('/api/admin/captcha', ['secret' => null, 'site_key' => self::TS_SITE_KEY])->assertJsonPath('secret_set', true);
        $this->actingAs($admin)->putJson('/api/admin/captcha', ['provider' => 'none', 'secret_clear' => true])->assertOk()
            ->assertJsonPath('stored.secret_set', false);

        // never in the audit trail either
        $this->assertStringNotContainsString($plain, json_encode(DB::table('audit_events')->get()->all()));
    }

    public function test_a_provider_needs_its_keys(): void
    {
        $admin = $this->coreUser(true);

        $this->actingAs($admin)->putJson('/api/admin/captcha', ['provider' => 'turnstile'])
            ->assertStatus(422)->assertJsonValidationErrors(['site_key', 'secret']);
        $this->actingAs($admin)->putJson('/api/admin/captcha', ['provider' => 'turnstile', 'site_key' => 'k'])
            ->assertStatus(422)->assertJsonValidationErrors(['secret']);
        $this->actingAs($admin)->putJson('/api/admin/captcha', ['provider' => 'friendly'])->assertStatus(422)->assertJsonValidationErrors('provider');
        $this->actingAs($admin)->putJson('/api/admin/captcha', ['forms' => ['login', 'contact']])->assertStatus(422)->assertJsonValidationErrors('forms.1');
        $this->actingAs($admin)->putJson('/api/admin/captcha', ['verify_timeout_ms' => 100])->assertStatus(422);

        // keys from the install count
        $this->installCaptcha(['site_key' => self::TS_SITE_KEY, 'secret' => self::TS_SECRET_PASS]);
        $this->actingAs($admin)->putJson('/api/admin/captcha', ['provider' => 'turnstile', 'fallback' => 'off', 'verify_timeout_ms' => 2500])->assertOk()
            ->assertJsonPath('fallback', 'off')->assertJsonPath('verify_timeout_ms', 2500);
    }

    public function test_only_who_manages_the_settings_changes_them(): void
    {
        // The install's first account is its Owner (AccessManager): the member comes after it,
        // with the default role (Developer, no settings.manage)
        $this->coreUser(true);
        $member = $this->coreUser(false);

        $this->getJson('/api/admin/captcha')->assertUnauthorized();
        $this->actingAs($member)->getJson('/api/admin/captcha')->assertForbidden();
        $this->actingAs($member)->putJson('/api/admin/captcha', ['provider' => 'none'])->assertForbidden();
    }

    public function test_the_legacy_signup_captcha_env_guards_the_sign_up_form_only(): void
    {
        config([
            'whitelabel.signup.captcha_provider' => 'hcaptcha',
            'whitelabel.signup.captcha_site_key' => 'h-site',
            'whitelabel.signup.captcha_secret' => 'h-secret',
        ]);
        $this->flushCaptcha();

        $settings = fn () => app(CaptchaSettings::class);
        $this->assertSame('hcaptcha', $settings()->platform()->provider);
        $this->assertSame(['register'], $settings()->platform()->forms);
        $this->assertSame('h-secret', $settings()->platform()->secret);
        $this->assertTrue($settings()->installDefaults()['legacy']);

        $this->getJson('/api/auth/registration-status')->assertJsonPath('captcha', ['provider' => 'hcaptcha', 'site_key' => 'h-site']);
        $this->getJson('/api/captcha/config')->assertJsonPath('forms', ['register']);

        // CAPTCHA_PROVIDER takes over (all forms by default)
        $this->turnstileInstall();
        $this->assertSame('turnstile', $settings()->platform()->provider);
        $this->assertFalse($settings()->installDefaults()['legacy']);
        $this->assertSame(CaptchaSettings::FORMS, $settings()->platform()->forms);
    }

    public function test_the_stored_signup_captcha_moves_into_the_platform_settings(): void
    {
        $this->setting('signup_captcha_provider', 'turnstile');
        $this->setting('signup_captcha_site_key', self::TS_SITE_KEY);
        SecureSetting::setValue('signup_captcha_secret', 'moved-secret');

        $migration = require database_path('migrations/2026_11_03_100001_create_captcha_settings_table.php');
        $migration->down();
        $migration->up();
        $this->flushCaptcha();

        $row = CaptchaSetting::platform();
        $this->assertSame('turnstile', $row->provider);
        $this->assertSame(self::TS_SITE_KEY, $row->site_key);
        $this->assertSame('moved-secret', $row->secretValue());
        $this->assertSame(['register'], $row->forms, 'the CAPTCHA stays on the sign-up form, as before');

        $config = app(CaptchaSettings::class)->platform();
        $this->assertTrue($config->protects('register'));
        $this->assertFalse($config->protects('login'));
        $this->assertSame('platform', $config->source);
    }

    public function test_a_fresh_install_imports_nothing(): void
    {
        $migration = require database_path('migrations/2026_11_03_100001_create_captcha_settings_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame(0, CaptchaSetting::query()->count());
    }

    public function test_the_admin_payload_reports_the_fallback(): void
    {
        $admin = $this->coreUser(true);
        $this->turnstileInstall();
        Http::fake([self::TS_HOST => Http::response('', 503)]);

        $this->actingAs($admin)->getJson('/api/admin/captcha')
            ->assertJsonPath('fallback_active', false)
            ->assertJsonPath('fallback_total', 0)
            ->assertJsonPath('fallback_last_at', null);

        $this->coreUser(false, ['username' => 'jane']);
        $this->postJson('/api/auth/login', ['login' => 'jane', 'password' => 'password', 'captcha_token' => 'tok'])->assertStatus(409);

        $this->actingAs($admin)->getJson('/api/admin/captcha')
            ->assertJsonPath('fallback_active', true)
            ->assertJsonPath('fallback_total', 1);
    }
}
