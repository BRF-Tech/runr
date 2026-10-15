<?php

namespace Tests\Feature\Billing;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Charge;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Upgrade of the 0.4 payment records (docs/upgrade.md): payment_records becomes charges — the
 * billing service's invoice id is kept as external_ref, cancelled records are void, records of an
 * ended period are closed with their invoiced (else recorded) amount, the others stay open.
 */
class ChargesMigrationTest extends TestCase
{
    use RefreshDatabase;
    use WiresTenancy;

    private const MIGRATION = 'Modules/Tenancy/Database/Migrations/2026_10_14_000001_turn_payment_records_into_charges.php';

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

    private function record(array $attrs): string
    {
        $id = (string) Str::ulid();
        DB::connection('central')->table('payment_records')->insert(array_merge([
            'id' => $id, 'tenant_id' => 't1', 'amount' => 10, 'currency' => 'USD', 'payment_date' => '2026-09-30',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        return $id;
    }

    public function test_payment_records_become_charges_and_back(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $this->assertTrue(Schema::connection('central')->hasTable('payment_records'));
        $this->assertFalse(Schema::connection('central')->hasTable('charges'));

        $invoiced = $this->record(['status' => 'paid', 'notes' => 'Usage 2026-09-01 – 2026-09-30', 'amount' => 0, 'invoice_id' => 'inv_42', 'invoice_total' => 87.5, 'amount_paid' => 87.5]);
        $cancelled = $this->record(['status' => 'cancelled', 'notes' => 'Plan Pro (month) 2026-09-01 – 2026-09-30']);
        $overdue = $this->record(['status' => 'overdue', 'notes' => 'Consulting']);
        $running = $this->record(['period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(), 'notes' => 'Plan Pro (month) x']);

        $migration->up();

        $this->assertFalse(Schema::connection('central')->hasTable('payment_records'));
        $this->assertFalse(Schema::connection('central')->hasColumn('charges', 'invoice_id'));
        $this->assertFalse(Schema::connection('central')->hasColumn('charges', 'payment_date'));

        $charge = Charge::query()->findOrFail($invoiced);
        $this->assertSame(Charge::KIND_USAGE, $charge->kind);
        $this->assertSame(Charge::STATUS_CLOSED, $charge->status);
        $this->assertSame('inv_42', $charge->external_ref);
        $this->assertEquals(87.5, (float) $charge->total);
        $this->assertEquals(87.5, (float) $charge->amount_due);
        $this->assertNotNull($charge->closed_at);

        $this->assertSame([Charge::KIND_PLAN, Charge::STATUS_VOID], [Charge::query()->find($cancelled)->kind, Charge::query()->find($cancelled)->status]);
        $this->assertSame([Charge::KIND_MANUAL, Charge::STATUS_CLOSED], [Charge::query()->find($overdue)->kind, Charge::query()->find($overdue)->status]);
        $this->assertEquals(10, (float) Charge::query()->find($overdue)->total);
        $this->assertSame(Charge::STATUS_OPEN, Charge::query()->find($running)->status);

        // the new statuses are enforced, new rows start open
        $fresh = Charge::query()->create(['tenant_id' => 't2', 'amount' => 1, 'currency' => 'USD', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
        $this->assertSame(Charge::STATUS_OPEN, $fresh->fresh()->status);
        $this->expectException(QueryException::class);
        DB::connection('central')->table('charges')->where('id', $fresh->id)->update(['status' => 'paid']);
    }

    public function test_rolling_back_restores_the_payment_columns(): void
    {
        Charge::query()->create(['tenant_id' => 't1', 'amount' => 5, 'currency' => 'USD', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'status' => Charge::STATUS_CLOSED, 'external_ref' => 'INV-1', 'total' => 5, 'amount_due' => 5, 'closed_at' => now()]);

        (require base_path(self::MIGRATION))->down();

        $row = DB::connection('central')->table('payment_records')->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame('INV-1', $row->invoice_id);
        $this->assertSame('2026-09-30', substr((string) $row->payment_date, 0, 10));
        $this->assertFalse(Schema::connection('central')->hasColumn('payment_records', 'external_ref'));
    }
}
