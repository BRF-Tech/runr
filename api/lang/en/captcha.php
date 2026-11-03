<?php

/*
| Bot protection: the CAPTCHA of the anonymous forms and its local fallback (docs/security.md#bot-protection)
*/
return [
    'fallback' => 'The verification service cannot be reached. Your browser solves a short check instead.',
    'off' => 'Bot protection is off.',

    // 422 / 409 / 503 {captcha: <reason>}
    'reasons' => [
        'required' => 'Complete the bot check.',
        'invalid' => 'The bot check failed or expired. Please try again.',
        'fallback_inactive' => 'The verification service is back. Complete the bot check again.',
        'provider' => 'The bot check could not load in your browser, but the verification service can be reached: turn off your ad blocker or content blocker for this page and try again.',
        'unavailable' => 'The verification service cannot be reached. Try again in a moment.',
        'unprotected' => 'This form has no bot check.',
        'bad_payload' => 'The answer of the browser check is not readable. Please try again.',
        'bad_algorithm' => 'The answer of the browser check is not readable. Please try again.',
        'bad_salt' => 'The answer of the browser check is not readable. Please try again.',
        'expired' => 'The browser check expired. Please try again.',
        'bad_number' => 'The answer of the browser check is wrong. Please try again.',
        'bad_signature' => 'The answer of the browser check is wrong. Please try again.',
        'bad_hash' => 'The answer of the browser check is wrong. Please try again.',
        'replayed' => 'This browser check was already used. Please try again.',
    ],

    // The administrators' alarm: more than one local check in 10 minutes (CaptchaFallbackAlert)
    'alert' => [
        'title' => 'Bot protection: the CAPTCHA provider cannot be reached',
        'body' => 'The browser check stood in for :provider :count times in the last 10 minutes: the provider, or the way out from the API to it, is failing. The sign-in and sign-up forms keep working meanwhile. Administration → Access & security → Bot protection shows the status of the fallback.',
    ],
    // The alarm of a wrong configuration (CaptchaConfigAlert): the forms run on the browser check
    'misconfigured' => [
        'title' => 'Bot protection: the CAPTCHA configuration is wrong',
        'body' => ':problem The sign-in and sign-up forms keep working on the browser check until it is fixed. Correct the keys in Administration → Access & security → Bot protection.',
        'reasons' => [
            'site_key_missing' => 'The site key of :provider is missing.',
            'secret_missing' => 'The secret key of :provider is missing.',
            'secret_unreadable' => 'The stored secret key of :provider cannot be decrypted (the application key changed): enter it again.',
            'secret_rejected' => ':provider refuses the secret key: it is wrong, or it does not belong to the site key.',
        ],
    ],
    'providers' => [
        'turnstile' => 'Cloudflare Turnstile',
        'recaptcha' => 'Google reCAPTCHA',
        'hcaptcha' => 'hCaptcha',
        'none' => 'no provider',
    ],

    'settings' => [
        'site_key_required' => 'A provider needs its site key.',
        'secret_required' => 'A provider needs its secret key.',
    ],
];
