<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\SecureSetting;
use App\Support\Secrets\SecretColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Modules\Tenancy\Services\Billing\BillingSettings;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\UsesEncryptionKeys;
use Tests\TestCase;

/**
 * White-label secrets and the audit log (docs/security.md): secure_settings (billing webhook
 * secret, CAPTCHA secret) are rotated with the other secret columns, settings changes are audited
 * with field names only.
 */
class WhiteLabelSecretsAuditTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase, UsesEncryptionKeys;

    private function raw(string $key): ?string
    {
        return DB::table('secure_settings')->where('key', $key)->value('value');
    }

    public function test_secure_settings_are_in_the_secret_column_registry(): void
    {
        $this->assertSame(['value' => SecretColumns::KEYED], SecretColumns::central()['secure_settings']);
        $this->assertArrayNotHasKey('secure_settings', SecretColumns::tenant(), 'platform rows live in the central database only');
        $this->assertSame('key', SecretColumns::keyColumn('secure_settings'));
        $this->assertSame('id', SecretColumns::keyColumn('project_secrets'));
    }

    public function test_secrets_encrypt_rotate_re_encrypts_secure_settings(): void
    {
        $old = self::newKey();
        $this->useKeys($old);
        SecureSetting::setValue(BillingSettings::SECRET_WEBHOOK, 'whsec_do_not_leak');
        SecureSetting::setValue('signup_captcha_secret', 'captcha-do-not-leak');

        $new = self::newKey();
        $this->useKeys($new, [$old]);

        $this->artisan('secrets:encrypt', ['--rotate' => true])->assertSuccessful();

        $this->assertSame('whsec_do_not_leak', $this->encrypterFor($new)->decryptString($this->raw(BillingSettings::SECRET_WEBHOOK)));
        $this->assertSame('captcha-do-not-leak', $this->encrypterFor($new)->decryptString($this->raw('signup_captcha_secret')));

        // Readable without the previous key now
        $this->useKeys($new);
        $this->assertSame('whsec_do_not_leak', SecureSetting::getValue(BillingSettings::SECRET_WEBHOOK));
    }

    public function test_a_signup_settings_change_is_audited_with_field_names_only(): void
    {
        $owner = $this->userWithRole('owner');

        $this->signedIn($owner)->putJson('/api/admin/signup-settings', [
            'captcha_provider' => 'turnstile',
            'captcha_site_key' => 'site-key',
            'captcha_secret' => 'captcha-do-not-leak',
        ])->assertOk()->assertDontSee('captcha-do-not-leak');

        $this->signedIn($owner)->getJson('/api/admin/signup-settings')
            ->assertOk()
            ->assertJsonPath('captcha_secret_set', true)
            ->assertDontSee('captcha-do-not-leak');

        $event = AuditEvent::where('action', 'signup.settings.update')->sole();
        $this->assertSame(AuditEvent::RESULT_SUCCESS, $event->result);
        $this->assertContains('captcha_secret', $event->metadata['fields']);
        foreach (AuditEvent::all() as $any) {
            $this->assertStringNotContainsString('captcha-do-not-leak', json_encode($any->getAttributes()));
        }
    }

    public function test_a_billing_settings_change_is_audited_with_field_names_only(): void
    {
        config(['tenancy.enabled' => true]);
        Router::middleware(['api', 'auth:sanctum'])->prefix('api')->group(base_path('Modules/Tenancy/routes/white-label.php'));
        $admin = $this->platformAdmin();

        $this->signedIn($admin)->putJson('/api/admin/billing-settings', [
            'webhook_url' => 'https://billing.example.com/runr',
            'webhook_secret' => 'whsec-do-not-leak-0123456789',
        ])->assertOk()
            ->assertJsonPath('webhook.url', 'https://billing.example.com/runr')
            ->assertJsonPath('webhook.secret_set', true)
            ->assertDontSee('whsec-do-not-leak');

        $this->assertSame('whsec-do-not-leak-0123456789', SecureSetting::getValue(BillingSettings::SECRET_WEBHOOK));
        $this->assertNotSame('whsec-do-not-leak-0123456789', $this->raw(BillingSettings::SECRET_WEBHOOK), 'stored encrypted');

        $event = AuditEvent::where('action', 'billing.settings.update')->sole();
        $this->assertEqualsCanonicalizing(['webhook_url', 'webhook_secret'], $event->metadata['fields']);
        $this->assertStringNotContainsString('whsec-do-not-leak', json_encode($event->getAttributes()));
    }
}
