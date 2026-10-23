<?php

namespace Modules\Tenancy\Services;

use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Services\Concerns\ConfigDomainTrait;
use App\Services\Dns\RegionDnsCheck;
use App\Services\DomainMigrateService;
use App\Services\DomainMoveFollower;
use App\Services\Helpers\RegionDomains;
use App\Services\KubernetesClient;
use App\Services\PublicHostMove;
use App\Services\RegionDomainAliasService;
use App\Services\RegionStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * An organization's own base domain (docs/tenancy.md → Organization domain): its functions answer
 * at <project>.<region>.<domain> — on the platform regions it uses through a config-domain entry
 * <region>.<domain> that selects its workloads (runr/tenant: <slug>), on its own regions as their
 * function domain.
 *
 * A changed domain moves the way the platform's base domain does (DomainMigrateService): the
 * functions keep their current URLs and answer on the new domain too until DNS of the new
 * <region>.<domain> answers the region's external address, then the new domain takes over and
 * the old one is no longer served.
 *
 * - Platform regions: the organization's alias (RegionDomainAliasService, one per organization,
 *   only its services) serves <region>.<new domain> next to the config-domain entry of the
 *   current one. The move is kept on the tenant (domain_migration: from — null for the platform's
 *   domain —, to, started_at, regions still moving) and completed region by region by the
 *   schedule (completeResolvedMigrations) or by hand (complete()): the new entry is added, the
 *   old one removed, the alias removed.
 * - The organization's own regions: DomainMigrateService itself, in the organization's context,
 *   on its own regions only (domain_migration_status of each region).
 *
 * Setting the domain back before the move completed rolls it back; clearing it returns the
 * functions to the platform's domain (which answers already) at once. A region without a ready
 * Knative Serving serves nothing yet and gets the current domain when Serving comes up
 * (SetupTenantDomainsOnMigrate).
 */
class TenantDomainService
{
    use ConfigDomainTrait;

    /** Virtual attribute of the tenant (data column) holding a move in progress */
    public const STATE = 'domain_migration';

    public function __construct(
        private readonly RegionDomainAliasService $alias,
        private readonly RegionDnsCheck $dns,
        private readonly DomainMigrateService $migrate,
        private readonly DomainMoveFollower $follower,
    ) {}

    /**
     * The organization's own base domain changed from $old to $new (null = none of its own: the
     * platform's). Call after the new value is stored in the organization's settings.
     */
    public function changeBaseDomain(Tenant $tenant, ?string $old, ?string $new): void
    {
        $state = $this->state($tenant);
        if ($old === $new && ! $state) {
            return;
        }

        $pending = $this->movePlatformRegions($tenant, $state, $old, $new);
        $ownMoving = $this->moveOwnRegions($tenant, $old, $new);

        $this->saveState($tenant, $pending === [] && ! $ownMoving ? null : [
            'from' => $state ? $state['from'] : $old,
            'to' => $new,
            'started_at' => $state['started_at'] ?? now()->toIso8601String(),
            'regions' => $pending,
            'own' => $ownMoving,
        ]);
    }

    /**
     * Complete the moves whose DNS answers (the schedule).
     */
    public function completeResolvedMigrations(): void
    {
        /** @var Tenant $tenant */
        foreach (Tenant::all() as $tenant) {
            if (! $this->state($tenant)) {
                continue;
            }

            try {
                $this->completeTenant($tenant, false);
            } catch (Throwable $e) {
                Log::warning('Organization domain move not completed', ['tenant' => $tenant->slug, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Complete the organization's move now, whatever DNS answers (its administrator's call: DNS
     * that cannot be checked from the control plane, e.g. behind a proxy).
     */
    public function complete(Tenant $tenant): void
    {
        if ($this->state($tenant)) {
            $this->completeTenant($tenant, true);
        }
    }

    /**
     * The move in progress for the settings of the organization (call in its context), or null.
     *
     * @return array{from: ?string, to: ?string, started_at: ?string, regions: list<string>}|null
     */
    public function summary(Tenant $tenant): ?array
    {
        $state = $this->state($tenant);
        if (! $state) {
            return null;
        }

        $names = KnativeRegion::on('central')->whereIn('id', $state['regions'])->pluck('name')->all();
        if ($state['own']) {
            $names = array_merge($names, $this->ownRegions()->where('domain_migration_status', 'dns_check')->pluck('name')->all());
        }
        sort($names);

        return [
            'from' => $state['from'],
            'to' => $state['to'],
            'started_at' => $state['started_at'],
            'regions' => array_values(array_unique($names)),
        ];
    }

    /**
     * Remove the organization's domain from a single platform region (detach, deletion): its
     * config-domain entries — its own domain (not the platform's it falls back to) and the
     * domains of a move in progress — and its alias.
     */
    public function removeDomainFromRegion(Tenant $tenant, KnativeRegion $region): void
    {
        if (! $region->kubeconfig) {
            return;
        }

        $own = $tenant->run(fn () => Setting::query()->whereKey('base_domain')->value('value') ?: null);
        $state = $this->state($tenant);
        foreach (array_unique(array_filter([$own, $state['from'] ?? null, $state['to'] ?? null])) as $domain) {
            $this->safeRemoveDomain($tenant, $region, "{$region->name}.{$domain}");
        }
        if ($state) {
            $this->alias->remove($region, self::aliasName($tenant));
        }
    }

    public static function buildTenantSelector(string $tenantSlug): string
    {
        return "selector:\n  runr/tenant: {$tenantSlug}";
    }

    /**
     * Name of the organization's alias objects in a region's project namespace (a DNS label
     * whatever the slug).
     */
    public static function aliasName(Tenant $tenant): string
    {
        return RegionDomainAliasService::NAME.'-t'.substr(sha1((string) $tenant->getTenantKey()), 0, 12);
    }

    /**
     * The platform regions the organization uses.
     *
     * @return list<string> ids of the regions that serve the new domain through the alias
     */
    private function movePlatformRegions(Tenant $tenant, ?array $state, ?string $old, ?string $new): array
    {
        // The domain the organization's functions answer on (their Knative URLs): the one a move
        // in progress started from
        $from = $state ? $state['from'] : $old;
        $regions = $this->resolveRegions($tenant);

        // Cleared (back to the platform's domain, which answers already) or set back before the
        // move completed: the aliases go, a cleared domain takes the organization's entries along
        if ($new === null || $new === $from) {
            foreach ($regions as $region) {
                $live = $region->kubeconfig && $region->has_serving;
                $inTenant = fn (callable $rebuild) => $tenant->run($rebuild);
                // cleared: the organization's services answer on the platform's domain from now on —
                // its custom domains and gateway routes point there first (DomainMoveFollower)
                $platform = RegionDomains::functionDomain($region);
                $move = $new === null && $from && $platform ? new PublicHostMove(RegionDomains::forBase($region, $from), $platform, $tenant->slug) : null;
                if ($move && $live) {
                    $this->follower->beforeSwitch($region, $move);
                }

                if ($state) {
                    $this->alias->remove($region, self::aliasName($tenant));
                }
                if ($new === null && $from) {
                    $this->safeRemoveDomain($tenant, $region, "{$region->name}.{$from}");
                }
                $this->syncStatusBlock($region);

                if ($live && $move) {
                    $this->follower->afterSwitch($region, $move, $inTenant);
                } elseif ($live && $state) {
                    // a move set back before it completed: the rewrites and the organization's
                    // gateways follow the services' hosts at once
                    $this->follower->afterRollback($region, $inTenant);
                }
            }

            return [];
        }

        $pending = [];
        foreach ($regions as $region) {
            if (! $region->kubeconfig || ! $region->has_serving) {
                continue;
            }

            try {
                // to the services' public hosts: <region>.<the organization's domain>, or the
                // platform's function domain of the region without one of its own
                $this->alias->apply($region, RegionDomains::forBase($region, $new), self::aliasName($tenant), $tenant->slug,
                    $from ? RegionDomains::forBase($region, $from) : RegionDomains::functionDomain($region));
            } catch (Throwable $e) {
                Log::warning("TenantDomainService: the new domain of {$tenant->slug} is not served on region {$region->name} yet", ['error' => $e->getMessage()]);
            }
            $pending[] = (string) $region->id;
            $this->syncStatusBlock($region);
        }

        return $pending;
    }

    /**
     * The organization's own regions follow its domain, or the platform's without one.
     *
     * @return bool whether one of them moves (waits for DNS of the new domain)
     */
    private function moveOwnRegions(Tenant $tenant, ?string $old, ?string $new): bool
    {
        $platform = $this->platformBaseDomain();
        $from = $old ?? $platform;
        $to = $new ?? $platform;

        return (bool) $tenant->run(function () use ($from, $to) {
            try {
                if ($from && $to && $from !== $to) {
                    $this->migrate->startMigration($from, $to, $this->ownRegions());
                } elseif (! $from && $to) {
                    $this->migrate->addDomainToAllRegions($to, $this->ownRegions());
                } elseif ($from && ! $to) {
                    $this->migrate->removeDomainFromAllRegions($from, $this->ownRegions());
                }
            } catch (Throwable $e) {
                Log::warning('TenantDomainService: the domain of the organization\'s own regions did not move', ['error' => $e->getMessage()]);
            }

            return $this->ownRegions()->where('domain_migration_status', 'dns_check')->exists();
        });
    }

    private function completeTenant(Tenant $tenant, bool $force): void
    {
        $state = $this->state($tenant);
        $assigned = $this->resolveRegions($tenant)->keyBy(fn (KnativeRegion $r) => (string) $r->id);

        $remaining = [];
        foreach ($state['regions'] as $id) {
            $region = $assigned->get($id);
            if (! $region) {
                continue;   // deleted or detached: its entries and alias went with it
            }

            $target = RegionDomains::forBase($region, (string) $state['to']);
            if (! $force && $this->dns->pointsAt($target, $region->external_ip) !== true) {
                $remaining[] = $id;

                continue;
            }

            // the organization's custom domains and gateway routes point at the new hosts first:
            // its alias answers there until Knative serves them (DomainMoveFollower)
            $current = $state['from'] ? RegionDomains::forBase($region, (string) $state['from']) : RegionDomains::functionDomain($region);
            $move = $current && $current !== $target ? new PublicHostMove($current, $target, $tenant->slug) : null;
            if ($move) {
                $this->follower->beforeSwitch($region, $move);
            }

            $this->addDomainToRegion($tenant, $region, (string) $state['to']);
            if ($state['from']) {
                $this->safeRemoveDomain($tenant, $region, "{$region->name}.{$state['from']}");
            }
            $this->alias->remove($region, self::aliasName($tenant));
            $this->syncStatusBlock($region);
            // the organization's gateways in the region (their routes' Host rewrites) follow now
            if ($move) {
                $this->follower->afterSwitch($region, $move, fn (callable $rebuild) => $tenant->run($rebuild));
            }
            Log::info("Organization domain move completed for {$tenant->slug} on region {$region->name}");
        }

        $ownMoving = false;
        if ($state['own']) {
            $ownMoving = (bool) $tenant->run(function () use ($force) {
                if ($force) {
                    foreach ($this->ownRegions()->where('domain_migration_status', 'dns_check')->get() as $region) {
                        $this->migrate->completeMigration($region);
                    }
                } else {
                    $this->migrate->completeResolvedMigrations($this->ownRegions());
                }

                return $this->ownRegions()->where('domain_migration_status', 'dns_check')->exists();
            });
        }

        $this->saveState($tenant, $remaining === [] && ! $ownMoving ? null : array_merge($state, ['regions' => $remaining, 'own' => $ownMoving]));
    }

    /**
     * @return array{from: ?string, to: ?string, started_at: ?string, regions: list<string>, own: bool}|null
     */
    private function state(Tenant $tenant): ?array
    {
        $state = $tenant->getAttribute(self::STATE);
        if (! is_array($state) || ! array_key_exists('to', $state)) {
            return null;
        }

        return [
            'from' => $state['from'] ?? null,
            'to' => $state['to'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'regions' => array_values(array_map('strval', (array) ($state['regions'] ?? []))),
            'own' => (bool) ($state['own'] ?? false),
        ];
    }

    private function saveState(Tenant $tenant, ?array $state): void
    {
        if ($state === null && $tenant->getAttribute(self::STATE) === null) {
            return;
        }

        if ($state === null) {
            $tenant->offsetUnset(self::STATE);
        } else {
            $tenant->setAttribute(self::STATE, $state);
        }
        $tenant->save();
    }

    /**
     * The organization's own regions (call in its context): not copies of platform regions.
     *
     * @return Builder<KnativeRegion>
     */
    private function ownRegions(): Builder
    {
        return KnativeRegion::where('is_synced', false);
    }

    private function platformBaseDomain(): ?string
    {
        return Setting::on(config('tenancy.database.central_connection', 'central'))
            ->whereKey('base_domain')->value('value') ?: null;
    }

    /** The status block of a platform region that is not active covers the domains it answers on. */
    private function syncStatusBlock(KnativeRegion $region): void
    {
        if ($region->is_active || ! $region->has_serving || ! $region->kubeconfig || $region->is_demo) {
            return;
        }

        // central context: the region's served domains follow the platform's base domain
        tenancy()->central(fn () => app(RegionStatusService::class)->syncBlock($region));
    }

    private function addDomainToRegion(Tenant $tenant, KnativeRegion $region, string $baseDomain): void
    {
        if (! $region->kubeconfig || ! $region->has_serving) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
            $tenantDomain = "{$region->name}.{$baseDomain}";
            $selector = self::buildTenantSelector($tenant->slug);
            $this->addConfigDomain($k8s, $servingNs, $tenantDomain, $selector);
        } catch (Throwable $e) {
            Log::warning("TenantDomainService: failed to add domain to region {$region->name}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove the organization's config-domain entry $domain — only an entry that selects its
     * workloads: the platform's own <region>.<domain> (no selector) is never the organization's.
     */
    private function safeRemoveDomain(Tenant $tenant, KnativeRegion $region, string $domain): void
    {
        if (! $region->kubeconfig) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
            $current = $this->getConfigDomains($k8s, $servingNs)[$domain] ?? null;
            if ($current === null || trim((string) $current) !== trim(self::buildTenantSelector($tenant->slug))) {
                return;
            }
            $this->removeConfigDomain($k8s, $servingNs, $domain);
        } catch (Throwable $e) {
            Log::warning("TenantDomainService: failed to remove domain from region {$region->name}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return Collection<int, KnativeRegion>
     */
    private function resolveRegions(Tenant $tenant): Collection
    {
        return $tenant->regions()
            ->get()
            ->map(fn ($r) => KnativeRegion::on('central')->find($r->id))
            ->filter()
            ->values();
    }
}
