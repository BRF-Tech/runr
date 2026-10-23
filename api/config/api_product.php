<?php

use App\Support\Images\RegistryMove;

/*
|--------------------------------------------------------------------------
| API product of the gateways (docs/api-product.md)
|--------------------------------------------------------------------------
|
| gateway_auth_image   the region service that checks API keys / JWTs, limits and counts the
|                      calls of key and JWT routes (Traefik ForwardAuth, runtime/gateway-auth;
|                      Helm value apiProduct.gatewayAuthImage). Regions with an internal registry
|                      pull it through that registry.
| callback_url         RunR's base URL as the regions reach it (config sync, usage reports); empty:
|                      the base the region agent connects to (IngestUrl)
| sync_seconds         how often gateway-auth asks for changed keys / plans (ETag: unchanged = 304)
| flush_seconds        how often gateway-auth reports the calls it counted
| replicas             gateway-auth pods per region with Redis (counters shared there); a region
|                      without Redis runs one pod with in-memory counters
| rotation_overlap_minutes  default overlap of a rotated key (the old key works that long)
| usage_retention_days hourly usage rows kept (the monthly event has the month's totals)
|
*/

return [
    'gateway_auth_image' => RegistryMove::ref(env('RUNR_GATEWAY_AUTH_IMAGE') ?: 'ghcr.io/brf-tech/runr/gateway-auth:1.0.0'),
    'callback_url' => env('RUNR_GATEWAY_AUTH_CALLBACK_URL'),
    'sync_seconds' => (int) env('RUNR_GATEWAY_AUTH_SYNC_SECONDS', 10),
    'flush_seconds' => (int) env('RUNR_GATEWAY_AUTH_FLUSH_SECONDS', 30),
    'replicas' => (int) env('RUNR_GATEWAY_AUTH_REPLICAS', 2),
    'rotation_overlap_minutes' => (int) env('RUNR_API_KEY_ROTATION_OVERLAP_MINUTES', 1440),
    'usage_retention_days' => (int) env('RUNR_API_USAGE_RETENTION_DAYS', 400),
];
