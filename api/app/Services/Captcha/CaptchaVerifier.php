<?php

namespace App\Services\Captcha;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side check of a provider token (Cloudflare Turnstile, Google reCAPTCHA v2, hCaptcha).
 * The three share the siteverify contract: form POST {secret, response, remoteip} →
 * {"success": bool, "error-codes": [...]}.
 *
 * Four verdicts (shared contract captcha-turnstile-plan §3.5, docs/security.md#bot-protection):
 *   pass           success: true
 *   invalid        the provider answered success: false (invalid, expired, used token, a bot,
 *                  bad-request), or any HTTP 4xx — 429 and a 4xx page without JSON included: a
 *                  refusal, never an outage (a flood that makes the provider answer 429 must not
 *                  open the cheap fallback for everyone) — 422, never the fallback
 *   unreachable    network error, timeout, 5xx, a 2xx/3xx body that is not the provider's JSON
 *                  answer, or a 2xx JSON answer naming `internal-error` (the provider says it could
 *                  not verify: what the fallback is for) — after one retry; the local
 *                  proof-of-work fallback takes over. The token's format gate (wellFormed) runs
 *                  first, so a garbage token never gets this far
 *   misconfigured  a configuration error, which counts as an outage: no site key, no secret, a
 *                  stored secret that cannot be decrypted (the provider is not asked), or the
 *                  provider refusing the secret (SECRET_ERRORS, not retried) — the local fallback
 *                  takes over and the administrators are told (CaptchaConfigAlert)
 */
class CaptchaVerifier
{
    public const PASS = 'pass';

    public const INVALID = 'invalid';

    public const UNREACHABLE = 'unreachable';

    public const MISCONFIGURED = 'misconfigured';

    /** Attempts per token: one retry */
    public const ATTEMPTS = 2;

    /** Default siteverify endpoints (config/captcha.php endpoints.* may point elsewhere) */
    public const ENDPOINTS = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
        'hcaptcha' => 'https://api.hcaptcha.com/siteverify',
    ];

    /**
     * The configuration class is narrow (shared contract §3.5): only the codes that say the
     * install's keys are wrong — a missing or wrong secret (all three providers; Cloudflare answers
     * them with HTTP 400), and hCaptcha's key pair codes. Never `bad-request`: the token comes from
     * the client, and a broken token must not open the fallback window.
     */
    public const SECRET_ERRORS = ['missing-input-secret', 'invalid-input-secret', 'sitekey-secret-mismatch', 'invalid-keys'];

    /**
     * Longest token sent to each provider (shared contract §3.5: bounded before siteverify).
     * Measured 2026-11-03 (brf-mono, mail-41; RunR's own run): a live Turnstile token 730
     * characters, the Turnstile test keys' token 21, a live reCAPTCHA v2 token 2297, Google's
     * classic v2 test key 1657–1678. Turnstile: 2048, its documented maximum. reCAPTCHA v2: no
     * documented maximum — 8192, over three times the longest measured. hCaptcha: no documented
     * maximum, its tokens carry a signed payload and grow with it — 32768. No test depends on a
     * live provider: Google's test key may not issue tokens on an IP address or localhost.
     */
    public const TOKEN_MAX_LENGTH = ['turnstile' => 2048, 'recaptcha' => 8192, 'hcaptcha' => 32768];

    /**
     * The characters of a token: base64url and dots. Measured 2026-11-03: live Turnstile
     * `[A-Za-z0-9._-]`, the Turnstile test keys' `XXXX.DUMMY.TOKEN.XXXX` (`[A-Z.]`), reCAPTCHA v2
     * `[A-Za-z0-9_-]`. hCaptcha documents `10000000-aaaa-bbbb-cccc-000000000001` for its test
     * keys; its live tokens are `P1_` + a JWT (base64url, dots) — not measured.
     */
    public const TOKEN_PATTERN = '/\A[A-Za-z0-9._-]+\z/';

    public static function endpoint(string $provider): ?string
    {
        $url = trim((string) config("captcha.endpoints.{$provider}", ''));

        return $url !== '' ? $url : (self::ENDPOINTS[$provider] ?? null);
    }

    /**
     * Whether $token may go to $provider's siteverify: within its length and character set. A token
     * that is not is refused (422) without a call; only its length and character class are logged.
     */
    public static function wellFormed(string $provider, string $token): bool
    {
        $max = self::TOKEN_MAX_LENGTH[$provider] ?? 2048;
        $length = strlen($token);
        $charset = preg_match(self::TOKEN_PATTERN, $token) === 1;
        if ($length <= $max && $charset) {
            return true;
        }

        Log::info('Bot protection: a malformed CAPTCHA token was refused without asking the provider', [
            'provider' => $provider,
            'length' => $length,
            'max_length' => $max,
            'charset' => $charset ? 'base64url' : 'other',
        ]);

        return false;
    }

    public function verify(CaptchaConfig $config, string $token, ?string $ip = null): string
    {
        $endpoint = self::endpoint($config->provider);
        if ($endpoint === null || $config->problem() !== null) {
            return self::MISCONFIGURED;
        }

        $payload = array_filter([
            'secret' => $config->secret,
            'response' => $token,
            'remoteip' => $ip,
        ], fn ($v) => $v !== null && $v !== '');

        if ($config->provider === 'hcaptcha' && $config->siteKey !== null) {
            $payload['sitekey'] = $config->siteKey;
        }

        $seconds = max(0.5, $config->verifyTimeoutMs / 1000);

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $verdict = $this->attempt($config, $endpoint, $payload, $seconds);
            if ($verdict !== self::UNREACHABLE) {
                return $verdict;
            }
        }

        return self::UNREACHABLE;
    }

    /** @param  array<string, string>  $payload */
    private function attempt(CaptchaConfig $config, string $endpoint, array $payload, float $seconds): string
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->withOptions(['timeout' => $seconds, 'connect_timeout' => $seconds])
                ->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::warning('Bot protection: the CAPTCHA provider cannot be reached', ['provider' => $config->provider, 'error' => $e->getMessage()]);

            return self::UNREACHABLE;
        }

        $status = $response->status();
        if ($status >= 500) {
            Log::warning("Bot protection: the CAPTCHA provider answered {$status}", ['provider' => $config->provider]);

            return self::UNREACHABLE;
        }

        $body = $response->json();
        $errors = is_array($body) ? array_values(array_filter((array) ($body['error-codes'] ?? []), 'is_string')) : [];

        // The provider refuses the install's keys (whatever the status): a configuration error
        if (array_intersect($errors, self::SECRET_ERRORS) !== []) {
            Log::error('Bot protection: the CAPTCHA provider refuses the secret — check Settings → Bot protection', ['provider' => $config->provider, 'tenant' => $config->tenantId, 'errors' => $errors]);

            return self::MISCONFIGURED;
        }

        // A 4xx is the provider's refusal (429 too, with or without JSON), never an outage
        if ($status >= 400) {
            Log::info("Bot protection: the CAPTCHA provider answered {$status} — refused like an invalid token", ['provider' => $config->provider, 'errors' => $errors]);

            return self::INVALID;
        }

        if (! is_array($body) || ! is_bool($body['success'] ?? null)) {
            Log::warning('Bot protection: the CAPTCHA provider answered without its JSON', ['provider' => $config->provider, 'status' => $status]);

            return self::UNREACHABLE;
        }

        if ($body['success'] === true) {
            return self::PASS;
        }

        // The provider says it could not verify (a 2xx answer; a 4xx one was a refusal above)
        if (in_array('internal-error', $errors, true)) {
            Log::warning('Bot protection: the CAPTCHA provider answered internal-error', ['provider' => $config->provider, 'status' => $status]);

            return self::UNREACHABLE;
        }

        Log::info('Bot protection: CAPTCHA token rejected', ['provider' => $config->provider, 'errors' => $errors]);

        return self::INVALID;
    }
}
