<?php

namespace App\Services\WhiteLabel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side CAPTCHA check of a signup (Cloudflare Turnstile or hCaptcha). Both providers use the
 * same siteverify contract: form POST {secret, response, remoteip} → {"success": bool, ...}.
 *
 * Fails closed: a provider configured without a secret, an unreachable provider or an unexpected
 * answer rejects the signup (logged), so a misconfiguration never silently disables the check.
 */
class CaptchaVerifier
{
    public const ENDPOINTS = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'hcaptcha' => 'https://api.hcaptcha.com/siteverify',
    ];

    public function __construct(private readonly SignupSettings $signup) {}

    public function enabled(): bool
    {
        return $this->signup->captchaEnabled();
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        $provider = $this->signup->captchaProvider();
        if ($provider === 'none') {
            return true;
        }

        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 4096) {
            return false;
        }

        $secret = $this->signup->captchaSecret();
        if ($secret === null) {
            Log::error('Signup CAPTCHA is enabled without a secret key — signups are rejected', ['provider' => $provider]);

            return false;
        }

        $payload = array_filter([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ], fn ($v) => $v !== null && $v !== '');

        if ($provider === 'hcaptcha' && ($siteKey = $this->signup->captchaSiteKey())) {
            $payload['sitekey'] = $siteKey;
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(max(1, (int) config('whitelabel.signup.captcha_timeout', 10)))
                ->post(self::ENDPOINTS[$provider], $payload);
        } catch (\Throwable $e) {
            Log::warning('Signup CAPTCHA provider unreachable', ['provider' => $provider, 'error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Signup CAPTCHA provider answered '.$response->status(), ['provider' => $provider]);

            return false;
        }

        $ok = $response->json('success') === true;
        if (! $ok) {
            Log::info('Signup CAPTCHA rejected', ['provider' => $provider, 'errors' => $response->json('error-codes')]);
        }

        return $ok;
    }
}
