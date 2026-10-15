<?php

namespace Tests\Feature\WhiteLabel;

use App\Models\SecureSetting;
use App\Services\WhiteLabel\SignupSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\WhiteLabel\Concerns\CoreWhiteLabel;
use Tests\TestCase;

/**
 * Settings → Signup (GET / PUT /api/admin/signup-settings): defaults from config / Helm values,
 * stored values winning, the CAPTCHA secret write-only and encrypted at rest.
 */
class SignupSettingsTest extends TestCase
{
    use CoreWhiteLabel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoreWhiteLabel();
    }

    public function test_defaults_come_from_the_install_configuration(): void
    {
        config([
            'whitelabel.signup.email_verification' => true,
            'whitelabel.signup.allowed_domains' => 'example.com',
            'whitelabel.signup.captcha_provider' => 'hcaptcha',
            'whitelabel.signup.captcha_site_key' => 'site-key',
            'whitelabel.signup.captcha_secret' => 'from-helm',
        ]);

        $this->actingAs($this->coreUser(true))->getJson('/api/admin/signup-settings')->assertOk()->assertJson([
            'registration_enabled' => true,
            'email_verification' => true,
            'allowed_domains' => ['example.com'],
            'captcha_provider' => 'hcaptcha',
            'captcha_site_key' => 'site-key',
            'captcha_secret_set' => true,
            'multi_tenant' => false,
        ])->assertJsonMissingPath('captcha_secret');
    }

    public function test_updates_are_stored_and_the_secret_is_write_only_and_encrypted(): void
    {
        $admin = $this->coreUser(true);

        $response = $this->actingAs($admin)->putJson('/api/admin/signup-settings', [
            'email_verification' => true,
            'invite_only' => false,
            'allowed_domains' => ['@Example.com', '*.acme.org'],
            'captcha_provider' => 'turnstile',
            'captcha_site_key' => '0x4AAA',
            'captcha_secret' => 'top-secret',
            'terms_url' => 'https://example.com/terms',
            'default_cpu_quota_m' => 3000,
            'default_memory_quota_mb' => 6144,
            'verification_ttl_hours' => 24,
        ])->assertOk();

        $response->assertJson([
            'email_verification' => true,
            'allowed_domains' => ['example.com', '*.acme.org'],
            'captcha_provider' => 'turnstile',
            'captcha_secret_set' => true,
            'terms_url' => 'https://example.com/terms',
            'default_cpu_quota_m' => 3000,
            'default_memory_quota_mb' => 6144,
            'verification_ttl_hours' => 24,
        ]);
        $this->assertStringNotContainsString('top-secret', $response->getContent());

        // encrypted at rest
        $raw = DB::table('secure_settings')->where('key', SignupSettings::SECRET_CAPTCHA)->value('value');
        $this->assertNotSame('top-secret', $raw);
        $this->assertSame('top-secret', SecureSetting::getValue(SignupSettings::SECRET_CAPTCHA));

        // omitted / null keeps the secret
        $this->actingAs($admin)->putJson('/api/admin/signup-settings', ['captcha_secret' => null])->assertJsonPath('captcha_secret_set', true);
        $this->actingAs($admin)->putJson('/api/admin/signup-settings', ['captcha_secret' => ''])->assertJsonPath('captcha_secret_set', true);

        // explicit clear
        $this->actingAs($admin)->putJson('/api/admin/signup-settings', ['captcha_secret_clear' => true])->assertJsonPath('captcha_secret_set', false);
    }

    public function test_a_cleared_url_overrides_a_configured_default(): void
    {
        config(['whitelabel.signup.privacy_url' => 'https://example.com/privacy']);
        $admin = $this->coreUser(true);

        $this->actingAs($admin)->putJson('/api/admin/signup-settings', ['privacy_url' => null])
            ->assertOk()->assertJsonPath('privacy_url', null);
        $this->getJson('/api/auth/registration-status')->assertJsonPath('privacy_url', null);
    }

    public function test_validation_and_authorization(): void
    {
        $admin = $this->coreUser(true);

        $this->actingAs($admin)->putJson('/api/admin/signup-settings', ['captcha_provider' => 'recaptcha'])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/admin/signup-settings', ['allowed_domains' => ['not a domain']])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/admin/signup-settings', ['terms_url' => 'javascript:x'])->assertStatus(422);

        $this->actingAs($this->coreUser(false))->getJson('/api/admin/signup-settings')->assertForbidden();
    }

    public function test_the_registration_toggle_is_shared_with_the_existing_setting(): void
    {
        $admin = $this->coreUser(true);

        $this->actingAs($admin)->putJson('/api/admin/signup-settings', ['registration_enabled' => false])->assertOk();

        $this->assertSame('false', DB::table('settings')->where('key', 'registration_enabled')->value('value'));
        $this->getJson('/api/auth/registration-status')->assertJsonPath('enabled', false);
    }
}
