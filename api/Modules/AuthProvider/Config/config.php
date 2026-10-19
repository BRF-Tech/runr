<?php

return [
    'enabled' => env('AUTH_PROVIDER_ENABLED', false),

    // How long a started OAuth / OIDC sign-in (or account link) waits for the provider's callback, in minutes.
    // The encrypted `state` names a server-side entry that is used once.
    'state_ttl_minutes' => 5,

    // How long the console has to confirm an account link after the provider's callback, in minutes
    'link_ttl_minutes' => 5,

    // Supported drivers (`saml` = SAML 2.0, handled by SamlService — not a Socialite driver)
    'drivers' => ['google', 'github', 'microsoft', 'gitlab', 'oidc', 'saml'],

    // Guzzle options of the OAuth drivers' calls to the provider (token exchange, user info)
    'guzzle' => [
        'timeout' => 15,
        'connect_timeout' => 5,
    ],

    'oidc' => [
        // Discovery document and signing keys (JWKS) are cached; an unknown key ID refetches the keys once
        'discovery_cache_seconds' => 3600,
        'jwks_cache_seconds' => 300,

        // Clock skew accepted on the ID token's exp / iat / nbf
        'leeway_seconds' => 60,

        // Timeout of each call to the identity provider, in seconds
        'timeout' => 15,
    ],

    'saml' => [
        // How long an issued AuthnRequest ID is accepted as InResponseTo (single use)
        'request_ttl_minutes' => 10,

        // Base URL the SP endpoints (entity ID, ACS, metadata) are built from; defaults to APP_URL
        'sp_base_url' => env('SAML_SP_BASE_URL'),
    ],
];
