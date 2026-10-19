<?php

namespace Tests\Feature\Billing;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Models\Price;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Support\DefaultPrices;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * Price resolution (docs/billing.md#price-resolution): a region's own version → the default
 * version → the seeded defaults; within a scope the version in effect on a day. A missing price
 * is never zero. Versions through the API take effect today or later.
 */
class PriceResolutionTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->setUpBilling();
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_install_migration_seeds_the_default_prices(): void
    {
        $seeded = Price::query()->whereNull('region_id')->get();

        $this->assertCount(1, $seeded);
        $this->assertSame(DefaultPrices::SEED_EFFECTIVE_FROM, $seeded->first()->effectiveDate());
        $this->assertEqualsWithDelta(DefaultPrices::VALUES['cpu_vcpu_month'], $seeded->first()->cpu_vcpu_month, 0.0001);
    }

    public function test_a_region_without_prices_falls_back_to_the_defaults_and_never_to_zero(): void
    {
        $region = $this->billingRegion();
        $book = app(PriceBook::class);

        $resolved = $book->resolve($region->id, '2026-10-15');
        $this->assertSame('platform_default', $resolved->source);
        $this->assertGreaterThan(0, $resolved->get('cpu_vcpu_hour'));

        // Without any version at all: the seeded constants
        Price::query()->delete();
        $book->flush();
        $resolved = $book->resolve($region->id, '2026-10-15');
        $this->assertSame('default', $resolved->source);
        $this->assertSame(DefaultPrices::VALUES['memory_gb_month'], $resolved->get('memory_gb_month'));
    }

    public function test_the_regions_own_version_wins_and_versions_take_effect_on_their_date(): void
    {
        $region = $this->billingRegion();
        $this->priceVersion($region->id, '2026-01-01', ['cpu_vcpu_hour' => 0.10]);
        $this->priceVersion($region->id, '2026-10-20', ['cpu_vcpu_hour' => 0.20]);
        $book = app(PriceBook::class);

        $this->assertSame('region', $book->resolve($region->id, '2026-10-19')->source);
        $this->assertEqualsWithDelta(0.10, $book->resolve($region->id, '2026-10-19')->get('cpu_vcpu_hour'), 1e-9);
        $this->assertEqualsWithDelta(0.20, $book->resolve($region->id, '2026-10-20')->get('cpu_vcpu_hour'), 1e-9);
        // Before the region's first version: the default prices
        $this->assertSame('platform_default', $book->resolve($region->id, '2025-12-31')->source);
    }

    public function test_price_versions_through_the_api(): void
    {
        $admin = $this->userWithRole('owner');
        $region = $this->billingRegion();
        $payload = DefaultPrices::VALUES + ['region_id' => $region->id, 'effective_from' => '2026-11-01'];
        $payload['cpu_vcpu_month'] = 30;

        // In the past: refused (bills of earlier days stay as they are)
        $this->actingAs($admin)->postJson('/api/billing/prices', ['effective_from' => '2026-10-01'] + $payload)
            ->assertStatus(422)->assertJsonValidationErrors('effective_from');
        // Every price is required
        $this->actingAs($admin)->postJson('/api/billing/prices', ['region_id' => $region->id, 'effective_from' => '2026-11-01'])
            ->assertStatus(422)->assertJsonValidationErrors('cpu_vcpu_hour');
        // Unknown region
        $this->actingAs($admin)->postJson('/api/billing/prices', ['region_id' => '01JUNKNOWNREGION0000000000'] + $payload)
            ->assertStatus(422);

        $id = $this->actingAs($admin)->postJson('/api/billing/prices', $payload)
            ->assertCreated()->assertJsonPath('data.cpu_vcpu_month', 30)->json('data.id');

        // The same scope and date replaces the version
        $this->actingAs($admin)->postJson('/api/billing/prices', ['cpu_vcpu_month' => 31] + $payload)->assertCreated();
        $this->assertSame(1, Price::query()->where('region_id', $region->id)->count());
        $this->assertEqualsWithDelta(31, Price::query()->find($id)->cpu_vcpu_month, 1e-9);

        $this->actingAs($admin)->getJson('/api/billing/regions')->assertOk()
            ->assertJsonPath('data.0.scheduled_prices.0.effective_from', '2026-11-01');

        // A version not yet in effect can be deleted, one in effect cannot
        $this->actingAs($admin)->deleteJson("/api/billing/prices/{$id}")->assertNoContent();
        $effective = Price::query()->whereNull('region_id')->first();
        $this->actingAs($admin)->deleteJson("/api/billing/prices/{$effective->id}")
            ->assertStatus(409)->assertJsonPath('code', 'price_in_effect');
    }

    public function test_one_version_per_scope_and_date(): void
    {
        $admin = $this->userWithRole('owner');
        $region = $this->billingRegion();

        // The database refuses a second version of a scope and date — a region's and the default one
        foreach ([$region->id, null] as $scope) {
            $this->priceVersion($scope, '2026-12-01');
            try {
                DB::transaction(fn () => $this->priceVersion($scope, '2026-12-01'));
                $this->fail('a duplicate version for '.($scope ?? 'the defaults'));
            } catch (UniqueConstraintViolationException) {
            }
        }

        // Through the API the version of that date is replaced (default scope too)
        $payload = DefaultPrices::VALUES + ['region_id' => null, 'effective_from' => '2026-12-01'];
        $this->actingAs($admin)->postJson('/api/billing/prices', ['gpu_month' => 600] + $payload)->assertCreated();
        $this->actingAs($admin)->postJson('/api/billing/prices', ['gpu_month' => 650] + $payload)->assertCreated();
        $versions = Price::query()->whereNull('region_id')->whereDate('effective_from', '2026-12-01')->get();
        $this->assertCount(1, $versions);
        $this->assertEqualsWithDelta(650, $versions[0]->gpu_month, 1e-9);
    }

    public function test_duplicate_versions_of_an_earlier_build_keep_the_latest(): void
    {
        $region = $this->billingRegion();
        Schema::table('billing_prices', fn (Blueprint $t) => $t->dropUnique('billing_prices_scope_date_unique'));
        DB::statement('DROP INDEX billing_prices_default_date_unique');
        $older = $this->priceVersion($region->id, '2026-12-01', ['gpu_month' => 600]);
        $newer = $this->priceVersion($region->id, '2026-12-01', ['gpu_month' => 650]);
        Price::query()->whereKey($older->id)->update(['updated_at' => '2026-10-01 00:00:00']);
        Price::query()->whereKey($newer->id)->update(['updated_at' => '2026-10-02 00:00:00']);
        $this->priceVersion(null, '2026-12-01');
        $this->priceVersion(null, '2026-12-01');

        $harden = require base_path('Modules/Billing/Database/Migrations/2026_10_19_200004_harden_billing_tables.php');
        $harden->up();
        $harden->up();

        $this->assertSame([$newer->id], Price::query()->where('region_id', $region->id)->pluck('id')->all());
        $this->assertSame(1, Price::query()->whereNull('region_id')->whereDate('effective_from', '2026-12-01')->count());
        $this->expectException(UniqueConstraintViolationException::class);
        $this->priceVersion(null, '2026-12-01');
    }

    public function test_the_prices_overview_shows_current_scheduled_and_inherited_defaults(): void
    {
        $admin = $this->userWithRole('owner');
        $this->priceVersion(null, '2026-12-01', ['gpu_month' => 650]);

        $this->actingAs($admin)->getJson('/api/billing/prices')->assertOk()
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('defaults.current.effective_from', DefaultPrices::SEED_EFFECTIVE_FROM)
            ->assertJsonPath('defaults.scheduled.0.gpu_month', 650)
            ->assertJsonPath('inherited_defaults.source', 'default');
    }

    public function test_reading_needs_billing_view_and_writing_billing_manage(): void
    {
        $this->userWithRole('owner');
        $developer = $this->userWithRole('developer');
        $billing = $this->userWithRole('billing');

        $this->actingAs($developer)->getJson('/api/billing/prices')->assertForbidden();
        $this->actingAs($billing)->getJson('/api/billing/prices')->assertOk();

        $viewer = $this->customRole('bill-reader', ['billing.view']);
        $reader = $this->userWithRole($viewer->key);
        $this->actingAs($reader)->postJson('/api/billing/prices', DefaultPrices::VALUES + ['effective_from' => '2026-11-01'])
            ->assertForbidden()->assertJsonPath('permissions', ['billing.manage']);
    }
}
