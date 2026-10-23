<?php

namespace App\Services;

use App\Models\Gateway;
use App\Models\KnativeRegion;
use App\Services\Observability\TenantScope;
use Illuminate\Support\Facades\Log;

/**
 * What follows a region's public hosts when a domain move completes or rolls back
 * (DomainMigrateService for the platform's or an organization's own regions, TenantDomainService
 * for an organization's domain on the platform regions) — at once, not on the next status check:
 *
 *  - before the switch: the rewrites of custom domains, gateway routes and global routes point at
 *    the moved hosts (PublicHostRewrites::repoint) — the domain alias still answers there and
 *    hands the calls to the old hosts until Knative serves the new ones;
 *  - after the switch: the other domain aliases of the region that send to the old domain (an
 *    organization moving to its own domain meanwhile) send to the new one
 *    (RegionDomainAliasService::retarget), and the gateways of the region are rebuilt — their
 *    default host <subdomain>.<function domain> and their routes' Host rewrites;
 *  - after a rollback: the rewrites follow the services' status again (PublicHostRewrites::sync),
 *    and the gateways are rebuilt.
 *
 * Every step is logged and carried on from when it fails: the per-minute status check still
 * catches up.
 */
class DomainMoveFollower
{
    public function __construct(private readonly RegionDomainAliasService $alias) {}

    public function beforeSwitch(KnativeRegion $region, PublicHostMove $move): void
    {
        if (! $this->reachable($region)) {
            return;
        }

        try {
            $lines = PublicHostRewrites::repoint(new KubernetesClient($region->kubeconfig), self::namespace($region), $move);
            if ($lines !== []) {
                Log::info("Host rewrites of region {$region->name} follow the domain move", ['changes' => $lines]);
            }
        } catch (\Throwable $e) {
            Log::warning("Host rewrites of region {$region->name} did not follow the domain move", ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  callable(callable): void|null  $inScopes  runs its argument where the region's
     *                                                   gateways live (null: every schema from
     *                                                   central context, else the current one)
     */
    public function afterSwitch(KnativeRegion $region, PublicHostMove $move, ?callable $inScopes = null): void
    {
        if (! $this->reachable($region)) {
            return;
        }

        if ($move->owner === null) {
            try {
                $this->alias->retarget($region, $move->from, $move->to);
            } catch (\Throwable $e) {
                Log::warning("Domain aliases of region {$region->name} did not follow the domain move", ['error' => $e->getMessage()]);
            }
        }

        $this->rebuildGateways($region, $move, $inScopes);
    }

    /** @param  callable(callable): void|null  $inScopes  as afterSwitch() */
    public function afterRollback(KnativeRegion $region, ?callable $inScopes = null): void
    {
        if (! $this->reachable($region)) {
            return;
        }

        try {
            PublicHostRewrites::sync(new KubernetesClient($region->kubeconfig), self::namespace($region),
                servingNamespace: $region->knative_serving_namespace ?: 'knative-serving');
        } catch (\Throwable $e) {
            Log::warning("Host rewrites of region {$region->name} not checked after the domain rollback", ['error' => $e->getMessage()]);
        }

        $this->rebuildGateways($region, null, $inScopes);
    }

    /** @param  callable(callable): void|null  $inScopes */
    private function rebuildGateways(KnativeRegion $region, ?PublicHostMove $move, ?callable $inScopes): void
    {
        $rebuild = function () use ($region, $move) {
            try {
                $gateways = Gateway::query()->where('knative_region_id', $region->id)->with(['routes.project', 'knativeRegion'])->get();
            } catch (\Throwable $e) {
                // a schema without gateways (yet)
                Log::warning('Gateways not read after the domain move', ['region' => $region->id, 'error' => $e->getMessage()]);

                return;
            }
            foreach ($gateways as $gateway) {
                try {
                    app(GatewayService::class)->syncToCluster($gateway, $move);
                } catch (\Throwable $e) {
                    Log::warning('Gateway not rebuilt after the domain move', ['gateway' => $gateway->id, 'error' => $e->getMessage()]);
                }
            }
        };

        if ($inScopes !== null) {
            $inScopes($rebuild);
        } elseif (TenantScope::current() === null) {
            TenantScope::each(fn () => $rebuild());
        } else {
            $rebuild();
        }
    }

    private function reachable(KnativeRegion $region): bool
    {
        return (bool) $region->kubeconfig && ! $region->is_demo;
    }

    private static function namespace(KnativeRegion $region): string
    {
        return $region->project_namespace ?: $region->name;
    }
}
