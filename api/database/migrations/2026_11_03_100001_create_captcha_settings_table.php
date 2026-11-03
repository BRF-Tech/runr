<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bot protection (docs/security.md#bot-protection): the platform's settings (tenant_id null) and
 * the organizations' own provider keys (multi-tenant). The secret column holds ciphertext of the
 * application key (`encrypted` cast).
 *
 * The CAPTCHA of the sign-up form had its own settings (Settings → Signup: signup_captcha_provider,
 * signup_captcha_site_key, secure setting signup_captcha_secret). They move into the platform row
 * here; an install that had the CAPTCHA on keeps it on the sign-up form only (forms = register),
 * as before. The old rows stay untouched for a rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captcha_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->unique();
            $table->string('provider', 16)->nullable();
            $table->string('site_key')->nullable();
            $table->text('secret')->nullable();
            $table->json('forms')->nullable();
            $table->string('fallback', 8)->nullable();
            $table->unsignedInteger('verify_timeout_ms')->nullable();
            $table->timestamps();
        });

        $this->importSignupCaptcha();
    }

    public function down(): void
    {
        Schema::dropIfExists('captcha_settings');
    }

    private function importSignupCaptcha(): void
    {
        $stored = Schema::hasTable('settings')
            ? DB::table('settings')->whereIn('key', ['signup_captcha_provider', 'signup_captcha_site_key'])->pluck('value', 'key')->all()
            : [];
        // Same `encrypted` cast on both models: the ciphertext moves as it is
        $secret = Schema::hasTable('secure_settings')
            ? DB::table('secure_settings')->where('key', 'signup_captcha_secret')->value('value')
            : null;

        $provider = array_key_exists('signup_captcha_provider', $stored) ? strtolower(trim((string) $stored['signup_captcha_provider'])) : null;
        $siteKey = array_key_exists('signup_captcha_site_key', $stored) ? (string) $stored['signup_captcha_site_key'] : null;

        if ($provider === null && $siteKey === null && ($secret === null || $secret === '')) {
            return;   // nothing was set in the console: the install values (env) keep applying
        }

        $provider = in_array($provider, ['none', 'turnstile', 'hcaptcha'], true) ? $provider : null;
        $effective = $provider ?? strtolower(trim((string) config('whitelabel.signup.captcha_provider', 'none')));

        DB::table('captcha_settings')->insert([
            'tenant_id' => null,
            'provider' => $provider,
            'site_key' => $siteKey,
            'secret' => $secret === '' ? null : $secret,
            // The CAPTCHA was a sign-up check: it stays one until an administrator selects more forms
            'forms' => in_array($effective, ['turnstile', 'hcaptcha'], true) ? json_encode(['register']) : null,
            'fallback' => null,
            'verify_timeout_ms' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
