<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Models\Tenant;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Upgrade from 0.5.7 (docs/upgrade.md): the tenant billing tables of the Tenancy module — plans,
 * subscriptions, purchases, capacity deals, charges, cost configuration — are archived row by row
 * into billing_legacy_archive and dropped; the settings only they used go with them. Nothing is lost
 * silently, and a second run changes nothing.
 */
class LegacyBillingArchiveTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private const MIGRATION = 'Modules/Tenancy/Database/Migrations/2026_10_19_100004_archive_legacy_billing_tables.php';

    protected function setUp(): void
    {
        parent::setUp();
        // Central connection + every Tenancy migration — the archive migration among them dropped
        // the 0.5.7 tables of a fresh database: the columns the archive reads come back here
        $this->setUpTenancy();

        $schema = Schema::connection('central');
        $schema->create('cost_configs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('region_id', 26)->nullable();
            $t->string('currency', 3);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        $schema->create('plans', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('slug');
            $t->string('name');
            $t->timestamps();
        });
        $schema->create('capacity_deals', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('tenant_id');
            $t->timestamps();
        });
        $schema->create('tenant_subscriptions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('tenant_id');
            $t->string('plan_id', 26)->nullable();
            $t->timestamps();
        });
        $schema->create('billing_purchases', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('tenant_id');
            $t->string('kind', 10);
            $t->decimal('amount', 12, 2)->nullable();
            $t->timestamps();
        });
        $schema->create('charges', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('tenant_id');
            $t->string('kind', 20);
            $t->string('status', 20);
            $t->decimal('total', 12, 2)->nullable();
            $t->json('line_items')->nullable();
            $t->timestamps();
        });
        DB::connection('central')->table('billing_legacy_archive')->delete();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_every_row_is_archived_before_the_tables_are_dropped(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $db = DB::connection('central');

        $db->table('cost_configs')->insert(['id' => '01JCOSTCONFIG000000000000A', 'region_id' => null, 'currency' => 'EUR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $db->table('plans')->insert(['id' => '01JPLAN0000000000000000000', 'slug' => 'pro', 'name' => 'Pro', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('tenant_subscriptions')->insert(['id' => '01JSUB00000000000000000000', 'tenant_id' => $tenant->id, 'plan_id' => '01JPLAN0000000000000000000', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('billing_purchases')->insert(['id' => '01JPURCHASE000000000000000', 'tenant_id' => $tenant->id, 'kind' => 'credit', 'amount' => 50, 'created_at' => now(), 'updated_at' => now()]);
        $db->table('charges')->insert(['id' => '01JCHARGE00000000000000000', 'tenant_id' => $tenant->id, 'kind' => 'usage', 'status' => 'closed', 'total' => 12.5, 'line_items' => json_encode([['description' => 'Project: orders', 'total' => 12.5]]), 'created_at' => now(), 'updated_at' => now()]);
        $db->table('settings')->insert(['key' => 'plans_enabled', 'value' => '1', 'created_at' => now(), 'updated_at' => now()]);

        (require base_path(self::MIGRATION))->up();

        foreach (['cost_configs', 'plans', 'tenant_subscriptions', 'billing_purchases', 'charges', 'capacity_deals'] as $table) {
            $this->assertFalse(Schema::connection('central')->hasTable($table), "{$table} is dropped");
        }
        $this->assertTrue(Schema::connection('central')->hasTable('billing_events'), 'the event log stays');
        $this->assertFalse($db->table('settings')->where('key', 'plans_enabled')->exists());

        $archive = $db->table('billing_legacy_archive')->get()->keyBy(fn ($row) => $row->source_table.':'.$row->row_id);
        $this->assertCount(6, $archive);
        $charge = json_decode($archive['charges:01JCHARGE00000000000000000']->data, true);
        $this->assertSame('closed', $charge['status']);
        $this->assertEquals(12.5, $charge['total']);
        $this->assertSame((string) $tenant->id, $archive['charges:01JCHARGE00000000000000000']->tenant_id);
        $this->assertSame('EUR', json_decode($archive['cost_configs:01JCOSTCONFIG000000000000A']->data, true)['currency']);
        $this->assertNull($archive['plans:01JPLAN0000000000000000000']->tenant_id);
        $this->assertArrayHasKey('settings:plans_enabled', $archive->all());

        // A second run (the tables are gone) changes nothing
        (require base_path(self::MIGRATION))->up();
        $this->assertSame(6, $db->table('billing_legacy_archive')->count());
    }
}
