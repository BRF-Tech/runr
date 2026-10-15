<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Services\Concerns\ConfigDomainTrait;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class DomainMigrateService
{
    use ConfigDomainTrait;

    /**
     * Start domain migration: add new domain to all ready regions while keeping old.
     */
    public function startMigration(string $oldDomain, string $newDomain): void
    {
        $regions = $this->getReadyRegions();

        foreach ($regions as $region) {
            $this->addDomainToRegion($region, $newDomain);

            $region->update([
                'domain_migration_status' => 'dns_check',
                'domain_migration_started_at' => now(),
                'domain_migration_old_domain' => $oldDomain,
            ]);
        }
    }

    /**
     * Check if new domain's DNS resolves to region's external IP.
     */
    public function checkDns(KnativeRegion $region): bool
    {
        $settings = $region->effective_settings;
        $newDomain = $settings['knative_domain'] ?? null;
        $ip = $region->external_ip;

        if (! $newDomain || ! $ip) {
            return false;
        }

        $testHost = 'dns-check.'.$newDomain;
        $resolved = @gethostbyname($testHost);

        return $resolved !== $testHost && $resolved === $ip;
    }

    /**
     * Complete domain migration for a region: remove old domain from config-domain.
     */
    public function completeMigration(KnativeRegion $region): void
    {
        $oldDomain = $region->domain_migration_old_domain;

        if ($oldDomain) {
            $this->safeRemoveDomain($region, $oldDomain);
        }

        $region->update([
            'domain_migration_status' => 'completed',
            'domain_migration_started_at' => null,
            'domain_migration_old_domain' => null,
        ]);
    }

    /**
     * Add domain to all ready regions (first-time domain set).
     */
    public function addDomainToAllRegions(string $newDomain): void
    {
        foreach ($this->getReadyRegions() as $region) {
            $this->addDomainToRegion($region, $newDomain);
            $this->updateRegionDomainSetting($region, $newDomain);
        }
    }

    /**
     * Remove domain from all ready regions.
     */
    public function removeDomainFromAllRegions(string $oldDomain): void
    {
        foreach ($this->getReadyRegions() as $region) {
            $this->safeRemoveDomain($region, $oldDomain);
        }
    }

    /**
     * Auto-complete migrations that have been pending for 48+ hours.
     */
    public function autoCompleteExpiredMigrations(): void
    {
        $regions = KnativeRegion::where('domain_migration_status', 'dns_check')
            ->where('domain_migration_started_at', '<=', now()->subHours(48))
            ->get();

        foreach ($regions as $region) {
            $this->completeMigration($region);
            Log::info("Domain migration auto-completed (48h) for region {$region->name}");
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
     * @return Collection<KnativeRegion>
     */
    private function getReadyRegions()
    {
        return KnativeRegion::where('knative_serving_status', 'ready')
            ->where('is_demo', false)
            ->get();
    }
}
