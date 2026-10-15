<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * v0.3.9 added prometheus_managed / loki_managed and the function-TLS columns to the central
 * knative_regions only. A tenant-owned region then read prometheus_managed as null → false, so
 * its install silently skipped Prometheus/Loki/cAdvisor/HA, and writing these columns failed
 * (PostgreSQL 42703). TenantMigrations 0023/0024 add them; the code treats a missing value as
 * "managed" like the column default.
 */
class TenantRegionObservabilityColumnsTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_a_missing_managed_flag_counts_as_managed(): void
    {
        // Row read from a schema without the columns: no attribute at all
        $row = (new KnativeRegion)->newFromBuilder(['id' => '01TEST', 'name' => 'fn']);
        $this->assertNull($row->prometheus_managed);
        $this->assertTrue($row->isPrometheusManaged());
        $this->assertTrue($row->isLokiManaged());

        // New model: attribute defaults
        $new = new KnativeRegion(['name' => 'fn']);
        $this->assertTrue($new->prometheus_managed);
        $this->assertTrue($new->loki_managed);

        // An explicit false still means "operator-supplied"
        $external = (new KnativeRegion)->newFromBuilder(['id' => '01EXT', 'prometheus_managed' => 0, 'loki_managed' => 0]);
        $this->assertFalse($external->isPrometheusManaged());
        $this->assertFalse($external->isLokiManaged());
    }

    public function test_tenant_migrations_add_the_columns_idempotently(): void
    {
        $tenant = $this->createTenantWithSchema('acme', [
            'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
            'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
        ]);

        $tenant->run(function () {
            foreach (['prometheus_managed', 'loki_managed', 'loki_org_id', 'function_tls_mode', 'function_tls_secret_name', 'function_tls_expires_at'] as $column) {
                $this->assertFalse(Schema::hasColumn('knative_regions', $column), "{$column} exists before 0023/0024");
            }

            // Twice: the second run is a no-op (guarded like 0022)
            foreach ([1, 2] as $_) {
                (require base_path('Modules/Tenancy/Database/TenantMigrations/0023_add_observability_managed_flags_to_knative_regions.php'))->up();
                (require base_path('Modules/Tenancy/Database/TenantMigrations/0024_add_function_tls_to_knative_regions.php'))->up();
            }

            // A tenant-owned region that existed before the upgrade: the new columns take their default
            DB::table('knative_regions')->insert(['id' => '01OWN', 'name' => 'own', 'is_synced' => false]);
            $region = KnativeRegion::find('01OWN');
            $this->assertTrue($region->prometheus_managed);
            $this->assertTrue($region->loki_managed);
            $this->assertTrue($region->isPrometheusManaged());

            // …and the v0.3.9 fields are writable for it
            $region->update([
                'loki_org_id' => 'team-a',
                'function_tls_mode' => 'custom',
                'function_tls_secret_name' => 'runr-function-tls',
                'function_tls_expires_at' => now()->addYear(),
            ]);
            $fresh = $region->fresh();
            $this->assertSame('custom', $fresh->function_tls_mode);
            $this->assertSame('team-a', $fresh->loki_org_id);
        });
    }

    public function test_a_new_tenant_owned_region_is_managed_by_default(): void
    {
        $tenant = $this->createTenantWithSchema('acme');

        $tenant->run(function () {
            $region = KnativeRegion::create(['name' => 'own', 'project_namespace' => 'own']);
            $fresh = $region->fresh();

            $this->assertTrue($fresh->prometheus_managed);
            $this->assertTrue($fresh->loki_managed);
            $this->assertNull($fresh->loki_org_id);
        });
    }
}
