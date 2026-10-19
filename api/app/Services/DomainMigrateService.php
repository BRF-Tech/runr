<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Services\Concerns\ConfigDomainTrait;
use App\Services\Dns\RegionDnsCheck;
use App\Services\Helpers\RegionDomains;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The platform base domain (PATCH /api/settings base_domain) on the regions that follow it — a
 * region whose function domain is <region>.<base domain> (RegionDomains::followsBase). A region
 * with a function domain of its own is never touched.
 *
 * - First base domain: a region without a function domain gets <region>.<base domain>.
 * - Changed base domain: a migration. The region keeps its function domain (the Knative URLs of
 *   its projects) and answers on <region>.<new base domain> too (RegionDomainAliasService).
 *   Once DNS of the new domain answers the region's external address (checked every minute by
 *   the status check and hourly), or when the migration is completed by hand, the new domain
 *   becomes the function domain: Knative's config-domain and the KnativeServing resource, the
 *   region's knative_domain (project URLs, function TLS, the status block, request replay), and
 *   the old domain is no longer served. Setting the base domain back before that rolls it back.
 * - Cleared base domain: the regions that follow it lose their function domain.
 *
 * $regions (optional, on the public entry points): the regions to consider instead of every ready
 * region of the current database — the Tenancy module passes an organization's own regions, in
 * its context, when the organization's base domain changes (TenantDomainService).
 */
class DomainMigrateService
{
    use ConfigDomainTrait;

    public function __construct(
        private readonly RegionDomainAliasService $alias,
        private readonly RegionDnsCheck $dns,
    ) {}

    /**
     * The base domain changed from $oldDomain to $newDomain.
     *
     * @param  Builder<KnativeRegion>|null  $regions
     */
    public function startMigration(string $oldDomain, string $newDomain, ?Builder $regions = null): void
    {
        foreach ($this->getReadyRegions($regions) as $region) {
            // Moving already (the base domain went to $oldDomain earlier): the region still serves
            // the base domain it moves from
            $from = $region->is_domain_migrating ? $region->domain_migration_old_domain : $oldDomain;
            if (! $from || ! RegionDomains::followsBase($region, $from)) {
                continue;
            }

            if (! ($region->effective_settings['knative_domain'] ?? null)) {
                // Nothing served yet: the new domain right away
                $this->addDomainToRegion($region, $newDomain);
                $this->updateRegionDomainSetting($region, $newDomain);

                continue;
            }

            if ($from === $newDomain) {
                $this->rollback($region);

                continue;
            }

            try {
                $this->alias->apply($region, RegionDomains::forBase($region, $newDomain));
            } catch (\Throwable $e) {
                Log::warning("DomainMigrate: the new domain of region {$region->name} is not served yet", ['error' => $e->getMessage()]);
            }

            $region->update([
                'domain_migration_status' => 'dns_check',
                'domain_migration_started_at' => $region->is_domain_migrating ? $region->domain_migration_started_at : now(),
                'domain_migration_old_domain' => $from,
            ]);
            $this->syncStatusBlock($region->fresh());
        }
    }

    /**
     * Whether DNS of the domain the region moves to points at its external address.
     */
    public function checkDns(KnativeRegion $region): bool
    {
        return $this->dns->pointsAt(RegionDomains::migrationTarget($region), $region->external_ip) === true;
    }

    /** Complete the migration when its DNS check passes. */
    public function completeIfResolved(KnativeRegion $region): bool
    {
        if (! $region->is_domain_migrating || ! $this->checkDns($region)) {
            return false;
        }

        $this->completeMigration($region);
        Log::info("Domain migration completed (DNS resolves) for region {$region->name}");

        return true;
    }

    /**
     * The new domain becomes the region's function domain; the old one is no longer served.
     */
    public function completeMigration(KnativeRegion $region): void
    {
        $target = RegionDomains::migrationTarget($region);
        $current = RegionDomains::functionDomain($region);

        if ($target && $current && $region->kubeconfig) {
            $k8s = new KubernetesClient($region->kubeconfig);
            $servingNs = $region->knative_serving_namespace ?: 'knative-serving';

            $this->addConfigDomain($k8s, $servingNs, $target);
            $this->removeConfigDomain($k8s, $servingNs, $current);
            $this->replaceCrDomain($k8s, $servingNs, $current, $target);

            $settings = $region->knative_settings ?? [];
            $settings['knative_domain'] = $target;
            $region->update(['knative_settings' => $settings]);
        }

        $this->alias->remove($region);

        $region->update([
            'domain_migration_status' => 'completed',
            'domain_migration_started_at' => null,
            'domain_migration_old_domain' => null,
        ]);
        $this->syncStatusBlock($region->fresh());
    }

    /**
     * Stop a migration: the region keeps its function domain, the new one is no longer served.
     */
    public function rollback(KnativeRegion $region): void
    {
        $this->alias->remove($region);

        $region->update([
            'domain_migration_status' => null,
            'domain_migration_started_at' => null,
            'domain_migration_old_domain' => null,
        ]);
        $this->syncStatusBlock($region->fresh());
    }

    /**
     * First base domain: the regions without a function domain get <region>.<base domain>.
     *
     * @param  Builder<KnativeRegion>|null  $regions
     */
    public function addDomainToAllRegions(string $newDomain, ?Builder $regions = null): void
    {
        foreach ($this->getReadyRegions($regions) as $region) {
            if ($region->effective_settings['knative_domain'] ?? null) {
                continue;
            }

            $this->addDomainToRegion($region, $newDomain);
            $this->updateRegionDomainSetting($region, $newDomain);
        }
    }

    /**
     * Cleared base domain: the regions that follow it (or move from it) lose that function domain.
     *
     * @param  Builder<KnativeRegion>|null  $regions
     */
    public function removeDomainFromAllRegions(string $oldDomain, ?Builder $regions = null): void
    {
        foreach ($this->getReadyRegions($regions) as $region) {
            $bases = array_filter([$oldDomain, $region->domain_migration_old_domain]);
            if ($region->is_domain_migrating) {
                $this->rollback($region);
                $region->refresh();
            }

            foreach ($bases as $base) {
                $domain = RegionDomains::forBase($region, $base);
                if (($region->effective_settings['knative_domain'] ?? null) !== $domain) {
                    continue;
                }

                $this->safeRemoveDomain($region, $base);
                $settings = $region->knative_settings ?? [];
                unset($settings['knative_domain']);
                $region->update(['knative_settings' => $settings]);
            }
        }
    }

    /**
     * Complete the migrations whose DNS check passes (the hourly schedule; the status check does
     * the same for each region every minute).
     *
     * @param  Builder<KnativeRegion>|null  $regions
     */
    public function completeResolvedMigrations(?Builder $regions = null): void
    {
        $migrating = ($regions ?? KnativeRegion::where('is_demo', false))->where('domain_migration_status', 'dns_check')->get();

        foreach ($migrating as $region) {
            try {
                $this->completeIfResolved($region);
            } catch (\Throwable $e) {
                Log::warning("Domain migration of region {$region->name} not completed", ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Auto-repair missing knative_domain for a region that has serving ready.
     */
    public function repairDomainForRegion(KnativeRegion $region): bool
    {
        if (! $region->has_serving || ! $region->kubeconfig) {
            return false;
        }

        $repaired = false;

        // Repair missing knative_domain
        $baseDomain = Setting::get('base_domain');
        $knativeDomain = $region->effective_settings['knative_domain'] ?? null;
        if ($baseDomain && ! $knativeDomain) {
            $this->addDomainToRegion($region, $baseDomain);
            $this->updateRegionDomainSetting($region, $baseDomain);
            Log::info("DomainRepair: auto-repaired knative_domain for region {$region->name}");
            $repaired = true;
        }

        // Ensure domain-template is set (prevents namespace duplication in URLs)
        $this->repairDomainTemplate($region);

        return $repaired;
    }

    /**
     * Ensure config-network has domain-template set to skip namespace in URLs.
     */
    private function repairDomainTemplate(KnativeRegion $region): void
    {
        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
            $cm = $k8s->getResourceStatus('v1', 'ConfigMap', 'config-network', $servingNs);

            $template = $cm['data']['domain-template'] ?? null;
            if ($template === '{{.Name}}.{{.Domain}}') {
                return;
            }

            $data = $cm['data'] ?? [];
            $data['domain-template'] = '{{.Name}}.{{.Domain}}';

            $k8s->applyResource([
                'apiVersion' => 'v1',
                'kind' => 'ConfigMap',
                'metadata' => ['name' => 'config-network', 'namespace' => $servingNs],
                'data' => $data,
            ], $servingNs);

            Log::info("DomainRepair: set domain-template for region {$region->name}");
        } catch (\Throwable $e) {
            Log::warning("DomainRepair: failed to set domain-template for region {$region->name}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The KnativeServing resource names the function domain of the region that installed it
     * (spec.config.domain): the operator writes it into config-domain again on every reconcile,
     * so a domain removed from config-domain alone would come back. Only the region's own key is
     * replaced — the resource is shared by the regions of one cluster.
     */
    private function replaceCrDomain(KubernetesClient $k8s, string $servingNs, string $old, string $new): void
    {
        $cr = $k8s->getResourceStatus('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $servingNs);
        $domains = $cr['spec']['config']['domain'] ?? null;
        if (! is_array($domains) || ! array_key_exists($old, $domains)) {
            return;
        }

        $k8s->mergePatch('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $servingNs, [
            'spec' => ['config' => ['domain' => [$old => null, $new => $domains[$old] ?? '']]],
        ]);
    }

    private function removeCrDomain(KubernetesClient $k8s, string $servingNs, string $domain): void
    {
        $cr = $k8s->getResourceStatus('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $servingNs);
        $domains = $cr['spec']['config']['domain'] ?? null;
        if (! is_array($domains) || ! array_key_exists($domain, $domains)) {
            return;
        }

        $k8s->mergePatch('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $servingNs, [
            'spec' => ['config' => ['domain' => [$domain => null]]],
        ]);
    }

    /** The status block of a region that is not active covers the domains it answers on. */
    private function syncStatusBlock(KnativeRegion $region): void
    {
        if (! $region->is_active && $region->has_serving && $region->kubeconfig && ! $region->is_demo) {
            app(RegionStatusService::class)->syncBlock($region);
        }
    }

    private function addDomainToRegion(KnativeRegion $region, string $domain): void
    {
        if (! $region->kubeconfig || ! $region->has_serving) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
            $regionDomain = "{$region->name}.{$domain}";
            $this->addConfigDomain($k8s, $servingNs, $regionDomain);
        } catch (\Throwable $e) {
            Log::warning("DomainMigrate: failed to add domain to region {$region->name}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function safeRemoveDomain(KnativeRegion $region, string $domain): void
    {
        if (! $region->kubeconfig) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
            $regionDomain = "{$region->name}.{$domain}";
            $this->removeConfigDomain($k8s, $servingNs, $regionDomain);
            $this->removeCrDomain($k8s, $servingNs, $regionDomain);
        } catch (\Throwable $e) {
            Log::warning("DomainMigrate: failed to remove domain from region {$region->name}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function updateRegionDomainSetting(KnativeRegion $region, string $domain): void
    {
        $settings = $region->knative_settings ?? [];
        $settings['knative_domain'] = "{$region->name}.{$domain}";
        $region->update(['knative_settings' => $settings]);
    }

    /**
     * @param  Builder<KnativeRegion>|null  $regions
     * @return Collection<int, KnativeRegion>
     */
    private function getReadyRegions(?Builder $regions = null)
    {
        return ($regions ?? KnativeRegion::where('is_demo', false))
            ->where('knative_serving_status', 'ready')
            ->where('is_demo', false)
            ->get();
    }
}
