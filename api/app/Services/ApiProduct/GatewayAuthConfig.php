<?php

namespace App\Services\ApiProduct;

use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiKey;
use App\Models\ApiProduct\ApiPlan;
use App\Models\ApiProduct\GatewayAuthScope;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Services\Observability\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * What a region's gateway-auth enforces (GET /api/internal/gateway-auth/{region}/config): the key
 * and JWT routes of every organization with gateways in the region, the consumers and the usable
 * keys (hash only — never a key) of those gateways and of the organizations, and the plans they
 * use. Every id carries its organization (scope): a key only opens routes of its own organization
 * and, for a gateway consumer, of its own gateway.
 *
 * The ETag is derived from the revisions of the region's gateway_auth_scopes rows (and changes
 * every ten minutes, so a missed revision catches up): an unchanged configuration is answered
 * with 304 without being built. A scope that could not be read is listed in "partial" — the
 * service keeps what it had for it — and the version then never matches, so the next sync builds
 * again.
 */
class GatewayAuthConfig
{
    private const REFRESH_SECONDS = 600;

    /** @return Collection<int, GatewayAuthScope> */
    private function scopes(KnativeRegion $region): Collection
    {
        return GatewayAuthScope::query()->where('knative_region_id', $region->id)->orderBy('scope')->get();
    }

    public function etag(KnativeRegion $region): string
    {
        $parts = $this->scopes($region)->map(fn (GatewayAuthScope $row) => $row->scope.':'.$row->revision)->implode(',');

        return '"'.substr(hash('sha256', 'v1|'.$region->id.'|'.$parts.'|'.intdiv(time(), self::REFRESH_SECONDS)), 0, 32).'"';
    }

    /**
     * @return array{version: string, routes: list<array>, consumers: list<array>, keys: list<array>, plans: list<array>, partial: list<string>}
     */
    public function payload(KnativeRegion $region, string $etag): array
    {
        $out = ['version' => trim($etag, '"'), 'routes' => [], 'consumers' => [], 'keys' => [], 'plans' => [], 'partial' => []];

        foreach ($this->scopes($region) as $row) {
            try {
                TenantScope::run($row->scope === GatewayAuthScope::CENTRAL ? null : $row->scope, function () use ($region, $row, &$out) {
                    $this->collect($region, $row->scope, $out);
                });
            } catch (\Throwable $e) {
                $out['partial'][] = $row->scope;
                Log::warning('Gateway auth config: scope skipped', ['region' => $region->id, 'scope' => $row->scope, 'error' => $e->getMessage()]);
            }
        }

        if ($out['partial'] !== []) {
            $out['version'] .= '-partial';
        }

        return $out;
    }

    /** @param  array{routes: list<array>, consumers: list<array>, keys: list<array>, plans: list<array>}  $out */
    private function collect(KnativeRegion $region, string $scope, array &$out): void
    {
        $gateways = Gateway::query()->where('knative_region_id', $region->id)->with('routes')->get();

        $authRoutes = 0;
        foreach ($gateways as $gateway) {
            foreach ($gateway->routes as $route) {
                if (! $route->requiresAuth()) {
                    continue;
                }
                $authRoutes++;
                $out['routes'][] = [
                    'id' => $route->id,
                    'scope' => $scope,
                    'gateway_id' => $gateway->id,
                    'mode' => $route->auth_mode,
                    'jwt' => $route->auth_mode === GatewayRoute::AUTH_JWT ? self::jwt($route) : null,
                ];
            }
        }
        if ($authRoutes === 0) {
            return;
        }

        $gatewayIds = $gateways->pluck('id')->all();
        $consumers = ApiConsumer::query()
            ->where(fn ($q) => $q->whereIn('gateway_id', $gatewayIds)->orWhereNull('gateway_id'))
            ->with(['keys' => fn ($q) => $q->whereNull('revoked_at')
                ->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now()))])
            ->get();

        $planIds = [];
        foreach ($consumers as $consumer) {
            $out['consumers'][] = [
                'id' => $consumer->id,
                'scope' => $scope,
                'gateway_id' => $consumer->gateway_id,
                'plan_id' => $consumer->plan_id,
                'suspended' => $consumer->isSuspended(),
                'external_id' => $consumer->external_id,
                'subject' => $consumer->jwt_subject,
            ];
            $planIds[] = $consumer->plan_id;

            foreach ($consumer->keys as $key) {
                $out['keys'][] = self::key($key);
                $planIds[] = $key->plan_id;
            }
        }

        $planIds = array_values(array_unique(array_filter($planIds)));
        if ($planIds !== []) {
            foreach (ApiPlan::query()->whereIn('id', $planIds)->get() as $plan) {
                $out['plans'][] = ['id' => $plan->id] + $plan->limits();
            }
        }
    }

    /** @return array{jwks_url: string, issuer: string|null, audience: string|null, subject_claim: string, require_consumer: bool} */
    public static function jwt(GatewayRoute $route): array
    {
        $config = (array) ($route->jwt_config ?? []);

        return [
            'jwks_url' => (string) ($config['jwks_url'] ?? ''),
            'issuer' => ($config['issuer'] ?? null) ?: null,
            'audience' => ($config['audience'] ?? null) ?: null,
            'subject_claim' => (string) (($config['subject_claim'] ?? null) ?: 'sub'),
            'require_consumer' => (bool) ($config['require_consumer'] ?? false),
        ];
    }

    /** @return array{id: string, hash: string, consumer_id: string, plan_id: string|null, expires_at: int|null, routes: object|null} */
    private static function key(ApiKey $key): array
    {
        $routes = null;
        if (is_array($key->scopes)) {
            $routes = [];
            foreach ($key->scopes as $scope) {
                $routes[(string) $scope['route_id']] = $scope['methods'] ?? null;
            }
        }

        return [
            'id' => $key->id,
            'hash' => $key->key_hash,
            'consumer_id' => $key->consumer_id,
            'plan_id' => $key->plan_id,
            'expires_at' => $key->expires_at?->getTimestamp(),
            // JSON object (route id → methods or null), also when empty
            'routes' => $routes === null ? null : (object) $routes,
        ];
    }
}
