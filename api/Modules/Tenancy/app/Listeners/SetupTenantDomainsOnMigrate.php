<?php

namespace Modules\Tenancy\Listeners;

use App\Events\RegionDomainsConfigured;
use App\Models\Setting;
use App\Services\Concerns\ConfigDomainTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainService;

class SetupTenantDomainsOnMigrate
{
    use ConfigDomainTrait;

    public function handle(RegionDomainsConfigured $event): void
    {
        try {
            $tenants = DB::connection('central')
                ->table('tenant_regions')
                ->where('knative_region_id', $event->region->id)
                ->join('tenants', 'tenants.id', '=', 'tenant_regions.tenant_id')
                ->select('tenants.id', 'tenants.slug')
                ->get();

            foreach ($tenants as $tenant) {
                $tenantModel = Tenant::find($tenant->id);
                if (! $tenantModel) {
                    continue;
                }

                $baseDomain = null;
                $tenantModel->run(function () use (&$baseDomain) {
                    $baseDomain = Setting::get('base_domain');
                });

                if ($baseDomain) {
                    $tenantDomain = "{$event->region->name}.{$baseDomain}";
                    $selector = TenantDomainService::buildTenantSelector($tenant->slug);
                    $this->addConfigDomain($event->k8s, $event->servingNs, $tenantDomain, $selector);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SetupTenantDomainsOnMigrate: failed', ['error' => $e->getMessage()]);
        }
    }
}
