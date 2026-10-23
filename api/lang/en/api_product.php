<?php

// API product of the gateways (docs/api-product.md)
return [
    'key' => [
        'unknown_route' => 'This route is not a route of the consumer\'s gateway.',
        'not_usable' => 'Only an active key can be rotated; this one is revoked or expired.',
    ],
    'plan' => [
        'not_usable' => 'This plan belongs to another gateway.',
        'in_use' => 'Consumers or active keys use this plan. Move them to another plan first.',
        'name_taken' => 'A plan with this name already exists here.',
        'burst_without_rate' => 'A burst needs a per-second rate.',
        'burst_below_rate' => 'The burst cannot be lower than the per-second rate.',
    ],
    'consumer' => [
        'subject_taken' => 'Another consumer of this gateway has this JWT subject.',
    ],
    'gateway' => [
        'region_unusable' => 'Region :region has no cluster a gateway could run on.',
    ],
    'domain' => [
        'taken' => 'This domain is already used by a gateway or a project.',
        'nothing_pending' => 'No domain is waiting for verification.',
        'active' => 'The domain is verified and routed to the gateway.',
    ],
    'gateway_only' => [
        'no_domains' => 'The project is reachable only through its gateway route; custom domains would get around the route\'s authentication.',
        'has_domains' => 'Remove the project\'s custom domains first: they would get around the gateway.',
    ],
    'route' => [
        'jwks_required' => 'JWT authentication needs the JWKS URL of the token issuer.',
        'taken' => 'The :environment environment of the project already has a gateway route: change or remove that one.',
        'path_taken' => 'The gateway already has a route on :path.',
        'region_mismatch' => 'The :environment environment runs in region :region: a route to it belongs to a gateway in that region.',
    ],
    'auth_service' => [
        'no_callback_url' => 'RunR does not know its address as the region sees it. Set the region\'s agent URL or RUNR_GATEWAY_AUTH_CALLBACK_URL.',
        'starting' => 'API authentication is starting in the region. Until it is ready, the key and JWT routes answer 503 auth_unavailable.',
        'not_ready' => 'API authentication is not ready in the region (no ready gateway-auth pod; it may not reach RunR). The key and JWT routes answer 503 auth_unavailable until it is.',
        'install_failed' => 'API authentication could not be installed in the region: :error. The key and JWT routes answer 503 auth_unavailable until it is.',
        'middleware_failed' => 'The authentication of route :path could not be applied in the region; the route is not served until it is.',
        'ingress_failed' => 'The gateway\'s routes could not be applied in the region; they were removed there so that no key or JWT route serves without a check.',
    ],
    'usage' => [
        'range' => 'Choose a range of at most :days days, with "from" before "to".',
    ],
    'developer_page' => [
        'not_found' => 'This developer page does not exist.',
    ],
];
