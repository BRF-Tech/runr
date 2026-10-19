<?php

use Illuminate\Support\Env;

/*
| Billing (docs/billing.md): RunR calculates what every project owes per calendar month from the
| region's prices, approved fixed resources and metered usage. External invoicing systems read the
| bills and usage through the API and the signed webhook events; RunR never collects payments.
*/

// Env::get() is what env() does; Larastan only treats files under config_path() as configuration
return [
    // BILLING_ENABLED=true turns the module on (the API image also runs module:enable Billing)
    'enabled' => Env::get('BILLING_ENABLED', false),

    // One currency for the whole install (ISO 4217); prices carry no currency of their own
    'currency' => Env::get('BILLING_CURRENCY', 'USD'),

    /*
    | Webhook of the install / platform (a tenant sets its own in the console or the API).
    | X-RunR-Signature = "sha256=" + HMAC-SHA256(secret, "<X-RunR-Timestamp>.<raw body>").
    | Values saved through PUT /api/billing/settings win over these.
    */
    'webhook' => [
        'url' => Env::get('BILLING_WEBHOOK_URL', ''),
        'secret' => Env::get('BILLING_WEBHOOK_SECRET', ''),
        'timeout' => (int) Env::get('BILLING_WEBHOOK_TIMEOUT', 10),
        // Attempts per event (backoff 1 min … 24 h), then delivery status "failed"
        'max_attempts' => (int) Env::get('BILLING_WEBHOOK_MAX_ATTEMPTS', 10),
    ],

    'events' => [
        'retention_days' => (int) Env::get('BILLING_EVENTS_RETENTION_DAYS', 90),
    ],
];
