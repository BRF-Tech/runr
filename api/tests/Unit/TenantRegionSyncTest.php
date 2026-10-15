<?php

namespace Tests\Unit;

use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\TenantLocalRegion;
use PHPUnit\Framework\TestCase;

/**
 * Central → tenant region sync: the tenant copy is updated by mass assignment, so a field the
 * central region syncs but the tenant copy does not accept is silently dropped. Tenant deploys
 * take the sandbox RuntimeClass from the tenant copy.
 */
class TenantRegionSyncTest extends TestCase
{
    private const SANDBOX_FIELDS = [
        'sandbox_kata_status',
        'sandbox_kata_status_message',
        'sandbox_kata_hypervisor',
        'sandbox_gvisor_status',
        'sandbox_gvisor_status_message',
        'sandbox_xaas_runtime',
        'sandbox_faas_runtime',
        'sandbox_caas_runtime',
        'sandbox_build_runtime',
    ];

    public function test_sandbox_settings_reach_the_tenant_region_copy(): void
    {
        $central = (new CentralSyncableRegion)->getSyncedAttributeNames();
        $tenant = new TenantLocalRegion;

        foreach (self::SANDBOX_FIELDS as $field) {
            $this->assertContains($field, $central, "central region does not sync {$field}");
            $this->assertTrue($tenant->isFillable($field), "tenant region copy drops {$field}");
            $this->assertContains($field, $tenant->getSyncedAttributeNames());
        }
    }

    /**
     * Tenant reads of a platform region (metrics, logs, install decisions) go through its tenant
     * copy: the observability ownership flags and the Loki tenant ID must reach it
     * (tenant columns: TenantMigrations/0023, function TLS: 0024).
     */
    public function test_observability_and_function_tls_fields_reach_the_tenant_region_copy(): void
    {
        $central = (new CentralSyncableRegion)->getSyncedAttributeNames();
        $tenant = new TenantLocalRegion;

        foreach (['prometheus_url', 'prometheus_managed', 'loki_url', 'loki_managed', 'loki_org_id',
            'function_tls_mode', 'function_tls_secret_name', 'function_tls_expires_at'] as $field) {
            $this->assertContains($field, $central, "central region does not sync {$field}");
            $this->assertTrue($tenant->isFillable($field), "tenant region copy drops {$field}");
        }

        // A copy created by the sync before the central values arrive is managed, like the column default
        $this->assertTrue($tenant->prometheus_managed);
        $this->assertTrue($tenant->loki_managed);
        $this->assertTrue($tenant->is_synced);
    }

    public function test_kubeconfig_is_never_synced(): void
    {
        $this->assertNotContains('kubeconfig', (new CentralSyncableRegion)->getSyncedAttributeNames());
        $this->assertFalse((new TenantLocalRegion)->isFillable('kubeconfig'));
    }
}
