<?php

/*
| Bot protection (docs/security.md#bot-protection): a CAPTCHA on the anonymous forms — sign-in,
| sign-up, the demo sign-in, the public forms. Cloudflare Turnstile first, Google reCAPTCHA (v2
| checkbox) and hCaptcha as options; while the provider cannot be reached — or its keys are wrong:
| a configuration error counts as an outage — a local proof-of-work challenge stands in (never
| fail-open, never fail-closed).
|
| Every value here is the install default (Helm values captcha.* → env). Platform administrators
| override it per field in Settings → Access & security → Bot protection; an organization may use
| its own provider and keys (multi-tenant). Resolution: organization → platform → these values →
| off. The older SIGNUP_CAPTCHA_* variables (signup.captcha.* values) still apply while
| CAPTCHA_PROVIDER is "none": they protect the sign-up form only, as before.
*/
return [

    // none | turnstile | recaptcha | hcaptcha
    'provider' => env('CAPTCHA_PROVIDER', 'none'),
    'site_key' => env('CAPTCHA_SITE_KEY', ''),
    'secret' => env('CAPTCHA_SECRET', ''),

    // Comma-separated: login, register, password_reset, demo_login, public_forms; empty = all
    'forms' => env('CAPTCHA_FORMS', ''),

    // local | off — the proof-of-work fallback while the provider cannot be reached or its keys are
    // wrong ("off" answers 503 instead: for tests only)
    'fallback' => env('CAPTCHA_FALLBACK', 'local'),

    // Timeout of one siteverify call (one retry follows a network error, a timeout or a 5xx)
    'verify_timeout_ms' => (int) (env('CAPTCHA_VERIFY_TIMEOUT_MS') ?: 4000),

    // The local proof-of-work challenge (shared contract captcha-local-spec)
    'local' => [
        // Scan ceiling of a challenge: ~1-3 s on a desktop, < 8 s on a phone
        'maxnumber' => (int) (env('CAPTCHA_LOCAL_MAXNUMBER') ?: 200000),
        'ttl_seconds' => (int) (env('CAPTCHA_LOCAL_TTL') ?: 600),
        // HMAC key of the challenges; empty: derived from APP_KEY (every API pod shares it)
        'hmac_key' => env('CAPTCHA_LOCAL_HMAC_KEY', ''),
    ],

    // How long local answers are accepted after the provider could not be reached
    'fallback_window_seconds' => 120,

    // An accepted provider token is remembered this long: it is single-use
    'token_ttl_seconds' => 300,

    // siteverify endpoints (a test install may point one at an address that does not answer)
    'endpoints' => [
        'turnstile' => env('CAPTCHA_TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
        'recaptcha' => env('CAPTCHA_RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify'),
        'hcaptcha' => env('CAPTCHA_HCAPTCHA_VERIFY_URL', 'https://api.hcaptcha.com/siteverify'),
    ],

    // Cache store of the single-use marks, the fallback window and the counters (empty: default;
    // every API pod has to share it)
    'cache_store' => env('CAPTCHA_CACHE_STORE'),
];
