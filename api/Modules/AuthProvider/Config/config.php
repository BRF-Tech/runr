<?php

return [
    'enabled' => env('AUTH_PROVIDER_ENABLED', false),

    // State token TTL in minutes
    'state_ttl_minutes' => 5,

    // Supported drivers (`saml` = SAML 2.0, handled by SamlService — not a Socialite driver)
    'drivers' => ['google', 'github', 'microsoft', 'gitlab', 'oidc', 'saml'],

    'saml' => [
        // How long an issued AuthnRequest ID is accepted as InResponseTo (single use)
        'request_ttl_minutes' => 10,

        // Base URL the SP endpoints (entity ID, ACS, metadata) are built from; defaults to APP_URL
        'sp_base_url' => env('SAML_SP_BASE_URL'),
    ],
];
