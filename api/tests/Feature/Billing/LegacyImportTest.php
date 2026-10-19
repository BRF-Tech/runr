<?php

namespace Tests\Feature\Billing;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\Price;
use Modules\Billing\Support\DefaultPrices;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * Upgrade from 0.5.7 (docs/upgrade.md): the archived cost configurations become price versions,
 * closed charges become closed platform → tenant bills, the rest stays readable in the archive.
 */
class LegacyImportTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    private const MIGRATION = 'Modules/Billing/Database/Migrations/2026_10_19_200002_import_legacy_billing.php';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->setUpBilling();

        if (! Schema::hasTable('billing_legacy_archive')) {
            Schema::create('billing_legacy_archive', function (Blueprint $table) {
                $table->id();
                $table->string('source_table', 64);
                $table->string('row_id', 64);
                $table->string('tenant_id')->nullable();
                $table->longText('data');
                $table->timestamp('archived_at')->nullable();
            });
        }
        // The seeded default of the first migration run; the import puts the 0.5.7 global price in its place
        Price::query()->delete();
        DB::table('billing_settings')->where('key', 'legacy_imported')->delete();
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function archive(string $table, array $row, ?string $tenant = null): void
    {
        DB::table('billing_legacy_archive')->insert([
            'source_table' => $table, 'row_id' => (string) $row['id'], 'tenant_id' => $tenant,
            'data' => json_encode($row), 'archived_at' => now(),
        ]);
    }

    private function runImport(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    public function test_cost_configurations_and_closed_charges_are_imported_once(): void
    {
        $region = $this->billingRegion();
        $this->archive('cost_configs', [
            'id' => 'c1', 'region_id' => null, 'is_active' => true, 'currency' => 'EUR',
            'cpu_price_per_mc_ms' => 0.00000000002, 'memory_price_per_mib_ms' => 0, 'gpu_price_per_unit_ms' => 0,
            'cpu_price_per_core_month' => 20, 'memory_price_per_gb_month' => 2.5,
            'multiplier_http' => 5, 'multiplier_queue' => 2, 'multiplier_schedule' => 1,
            'kv_price_per_gb_hour' => 0, 'object_price_per_gb_hour' => 0, 'postgres_price_per_gb_hour' => 0, 'postgres_instance_price_per_hour' => 0,
        ]);
        $this->archive('cost_configs', ['id' => 'c2', 'region_id' => $region->id, 'is_active' => true, 'cpu_price_per_core_month' => 30]);
        $this->archive('cost_configs', ['id' => 'c3', 'region_id' => 'inactive', 'is_active' => false, 'cpu_price_per_core_month' => 99]);

        $this->archive('charges', ['id' => 'ch1', 'tenant_id' => 't-acme', 'kind' => 'usage', 'status' => 'closed', 'currency' => 'EUR',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'total' => 12, 'credit_applied' => 2, 'amount_due' => 10,
            'external_ref' => 'INV-1', 'closed_at' => '2026-09-01 02:30:00',
            'line_items' => json_encode([['description' => 'Project: api', 'quantity' => 100, 'unit' => 'sessions', 'unit_price' => 0.12, 'total' => 12]])], 't-acme');
        $this->archive('charges', ['id' => 'ch2', 'tenant_id' => 't-acme', 'kind' => 'plan', 'status' => 'closed', 'currency' => 'EUR',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'amount' => 49, 'total' => 49, 'credit_applied' => 0, 'amount_due' => 49,
            'notes' => 'Plan Pro', 'line_items' => null, 'closed_at' => '2026-09-01 02:30:00'], 't-acme');
        $this->archive('charges', ['id' => 'ch3', 'tenant_id' => 't-acme', 'kind' => 'manual', 'status' => 'void', 'currency' => 'EUR',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'total' => 5], 't-acme');
        $this->archive('charges', ['id' => 'ch4', 'tenant_id' => 't-acme', 'kind' => 'usage', 'status' => 'open', 'currency' => 'EUR',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31'], 't-acme');

        $this->runImport();
        $this->runImport();

        // Global → platform default; 0 meant "not set" → seeded default; units converted
        $default = Price::query()->whereNull('region_id')->sole();
        $this->assertSame(DefaultPrices::SEED_EFFECTIVE_FROM, $default->effectiveDate());
        $this->assertEqualsWithDelta(0.072, $default->cpu_vcpu_hour, 1e-9);
        $this->assertEqualsWithDelta(DefaultPrices::VALUES['memory_gb_hour'], $default->memory_gb_hour, 1e-9);
        $this->assertEqualsWithDelta(20.0, $default->cpu_vcpu_month, 1e-9);
        $this->assertEqualsWithDelta(5.0, $default->multiplier_http, 1e-9);
        $this->assertStringContainsString('EUR', (string) $default->note);
        $this->assertEqualsWithDelta(30.0, Price::query()->where('region_id', $region->id)->sole()->cpu_vcpu_month, 1e-9);
        $this->assertFalse(Price::query()->where('region_id', 'inactive')->exists());

        // One closed legacy bill for the tenant and month: what was due, the credit as a line
        $bill = Bill::query()->where('kind', Bill::TENANT)->sole();
        $this->assertSame(Bill::SOURCE_LEGACY, $bill->source);
        $this->assertSame(Bill::CLOSED, $bill->status);
        $this->assertSame('2026-08', $bill->period);
        $this->assertSame('EUR', $bill->currency);
        $this->assertEqualsWithDelta(59.0, $bill->total, 0.001);
        $this->assertSame('INV-1', $bill->payment_reference);
        $lines = collect($bill->line_items);
        $this->assertCount(3, $lines);
        $this->assertEqualsWithDelta(-2.0, $lines->firstWhere('type', 'credit')['total'], 0.001);
        $this->assertSame('Plan Pro', $lines->firstWhere('metadata.kind', 'plan')['description']);
    }

    public function test_the_archive_stays_readable_for_platform_administrators(): void
    {
        $admin = $this->platformAdmin();
        $this->archive('plans', ['id' => 'p1', 'slug' => 'pro', 'price_monthly' => 49]);

        $this->actingAs($admin)->getJson('/api/billing/legacy-records?source=plans')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.data.slug', 'pro');
    }

    public function test_converted_charges_have_a_subject_of_their_own(): void
    {
        $this->archive('charges', ['id' => 'ch1', 'tenant_id' => 't-acme', 'kind' => 'usage', 'status' => 'closed', 'currency' => 'USD',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'total' => 3, 'amount_due' => 3, 'closed_at' => '2026-10-15 02:30:00'], 't-acme');

        $this->runImport();

        $this->assertSame(Bill::legacyTenantKey('t-acme'), Bill::query()->where('kind', Bill::TENANT)->sole()->subject_key);
    }

    public function test_prices_in_another_currency_than_billing_currency_raise_a_warning(): void
    {
        $admin = $this->platformAdmin();
        $this->archive('cost_configs', ['id' => 'c1', 'region_id' => null, 'is_active' => true, 'currency' => 'eur', 'cpu_price_per_core_month' => 20]);
        Log::spy();

        $this->runImport();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'EUR') && str_contains($message, 'BILLING_CURRENCY is USD'))->once();
        $this->actingAs($admin)->getJson('/api/billing/settings')->assertOk()
            ->assertJsonPath('warnings.0.code', 'legacy_currency_mismatch')
            ->assertJsonPath('warnings.0.legacy_currencies', ['EUR'])
            ->assertJsonPath('warnings.0.currency', 'USD');

        // Once BILLING_CURRENCY is the currency of the 0.5.7 prices, the warning is gone
        config(['billing.currency' => 'EUR']);
        $this->actingAs($admin)->getJson('/api/billing/settings')->assertOk()->assertJsonPath('warnings', []);
    }

    public function test_amounts_of_an_earlier_build_get_three_decimals(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL keeps the precision of a numeric column (DB_CONNECTION=pgsql)');
        }
        Schema::table('billing_bills', fn (Blueprint $t) => $t->decimal('total', 14, 2)->nullable()->change());
        Schema::table('billing_fixed_requests', fn (Blueprint $t) => $t->decimal('monthly_price', 14, 2)->default(0)->change());

        (require base_path('Modules/Billing/Database/Migrations/2026_10_19_200004_harden_billing_tables.php'))->up();

        $type = fn (string $table, string $column) => collect(Schema::getColumns($table))->firstWhere('name', $column)['type'];
        $this->assertSame('numeric(16,3)', $type('billing_bills', 'total'));
        $this->assertSame('numeric(16,3)', $type('billing_fixed_requests', 'monthly_price'));
        $this->assertFalse(collect(Schema::getColumns('billing_fixed_requests'))->firstWhere('name', 'monthly_price')['nullable']);
    }

    public function test_the_billing_migrations_refuse_a_rollback_and_say_how_to_go_back(): void
    {
        foreach ([
            'Modules/Billing/Database/Migrations/2026_10_19_200001_create_billing_tables.php',
            self::MIGRATION,
            'Modules/Billing/Database/Migrations/2026_10_19_200003_create_billing_archived_bills_table.php',
            'Modules/Billing/Database/Migrations/2026_10_19_200004_harden_billing_tables.php',
            'Modules/Billing/Database/TenantMigrations/0060_create_billing_tables.php',
            'Modules/Billing/Database/TenantMigrations/0061_harden_billing_tables.php',
            'Modules/Tenancy/Database/Migrations/2026_10_19_100004_archive_legacy_billing_tables.php',
        ] as $path) {
            try {
                (require base_path($path))->down();
                $this->fail("{$path} rolled back");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('restore the database backup taken before the upgrade', $e->getMessage(), $path);
            }
        }
        $this->assertTrue(Schema::hasTable('billing_bills'), 'nothing was dropped');
    }

    public function test_a_fresh_install_gets_the_seeded_default_prices(): void
    {
        Schema::drop('billing_legacy_archive');
        $this->runImport();

        $this->assertSame(DefaultPrices::VALUES['cpu_vcpu_month'], Price::query()->whereNull('region_id')->sole()->cpu_vcpu_month);
    }
}
