<?php

/*
|--------------------------------------------------------------------------
| Client IP behind proxies (App\Http\Middleware\TrustProxies)
|--------------------------------------------------------------------------
|
| request()->ip() — the per-IP API rate limits (App\Support\ApiRateLimits) and the IPs in logs
| and audit records — comes from X-Forwarded-For only as far as the proxies in front of the API
| wrote it:
|
|  proxies  TRUSTED_PROXIES     Addresses the proxies connect from: CIDRs / addresses, comma- or
|                               space-separated. "private_ranges" = private, loopback and
|                               link-local ranges (covers a Kubernetes pod network), "*" = any
|                               peer, empty = no proxy (the peer is the client).
|  hops     TRUSTED_PROXY_HOPS  Proxies that append to X-Forwarded-For in front of the API.
|                               Helm: 2 with the chart's Ingress (ingress controller →
|                               runr-gateway), 1 when runr-gateway is exposed directly; compose: 1.
|
*/

return [

    'proxies' => env('TRUSTED_PROXIES', 'private_ranges'),

    'hops' => max(1, (int) env('TRUSTED_PROXY_HOPS', 1)),

];
