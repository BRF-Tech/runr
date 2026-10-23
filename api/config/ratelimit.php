<?php

/*
|--------------------------------------------------------------------------
| API rate limiting (HTTP 429)
|--------------------------------------------------------------------------
|
| Named limiters registered by App\Support\ApiRateLimits:
|
|  - `api`  (every route in the `api` middleware group)
|      per tenant   API_RATE_LIMIT_PER_MINUTE        (when a tenant context resolves)
|      per user     API_USER_RATE_LIMIT_PER_MINUTE   (authenticated calls, all tokens of a user)
|      per IP       API_GUEST_RATE_LIMIT_PER_MINUTE  (unauthenticated calls)
|  - `auth` (login, register, password change, invitation accept, OAuth/SAML sign-in)
|      per IP + account  AUTH_RATE_LIMIT_PER_MINUTE (the account the user name or e-mail
|                        address names: all spellings of one account share the budget)
|
| The per-user default leaves room for the console's own polling (deployment log view polls
| logs every second and metrics every two seconds) across a few open tabs.
|
*/

return [

    'enabled' => (bool) env('API_RATE_LIMIT_ENABLED', true),

    'api' => [
        'per_tenant_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 1200),
        'per_user_per_minute' => (int) env('API_USER_RATE_LIMIT_PER_MINUTE', 300),
        'per_guest_per_minute' => (int) env('API_GUEST_RATE_LIMIT_PER_MINUTE', 120),
    ],

    'auth' => [
        'per_minute' => (int) env('AUTH_RATE_LIMIT_PER_MINUTE', 10),
    ],

    /*
    | Paths (Request::is() patterns) the `api` limiter never throttles: machine-to-machine
    | traffic from the platform itself. The region agent talks to Reverb over /ws (not a Laravel
    | HTTP route), the Kubernetes probes use /up (outside the `api` group) — these patterns keep
    | any internal/agent/build/health route under /api unthrottled as well.
    */
    'except' => [
        'api/internal/*',
        'api/agent/*',
        'api/agents/*',
        'api/build/*',
        'api/builds/*',
        'api/health',
        'api/healthz',
        'up',
    ],

];
