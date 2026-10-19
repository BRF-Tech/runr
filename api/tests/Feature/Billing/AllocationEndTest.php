<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Providers\BillingServiceProvider;
use Modules\Billing\Services\BillService;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * An allocation ends with its project (docs/billing.md#fixed-resources) whether billing is on or
 * off, and the close job ends allocations whose project is gone or in another region — deleted or
 * moved without the hooks — so nothing is billed for a project that no longer holds them.
 */
class AllocationEndTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The module installed, billing switched off (it ran before: its tables exist). */
    private function billingOff(bool $migrated = true): void
    {
        config(['billing' => array_replace_recursive(require base_path('Modules/Billing/Config/config.php'), ['enabled' => false, 'currency' => 'USD'])]);
        if ($migrated) {
            Artisan::call('migrate', ['--path' => 'Modules/Billing/Database/Migrations', '--force' => true]);
        }
        $this->app->register(BillingServiceProvider::class);
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_allocations_end_with_their_project_while_billing_is_off(): void
    {
        $this->billingOff();
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $deleted = $this->billedProject('gone', $region, $owner);
        $moved = $this->billedProject('moved', $region, $owner);
        $a = $this->allocation($deleted, 1000, 1024, '2026-10-01');
        $b = $this->allocation($moved, 1000, 1024, '2026-10-01');

        $deleted->delete();
        $moved->update(['knative_region_id' => $this->billingRegion('other')->id]);

        $this->assertSame([FixedRequest::ENDED, '2026-10-20'], [$a->fresh()->status, $a->fresh()->active_until->toDateString()]);
        $this->assertSame([FixedRequest::ENDED, '2026-10-20'], [$b->fresh()->status, $b->fresh()->active_until->toDateString()]);
        // Billing itself stays off: no routes
        $this->actingAs($owner)->getJson('/api/billing/bills')->assertNotFound();
    }

    public function test_a_project_is_deleted_where_billing_never_ran(): void
    {
        $this->billingOff(false);
        $owner = $this->userWithRole('owner');
        $project = $this->billedProject('api', $this->billingRegion(), $owner);

        DB::transaction(fn () => $project->delete());

        $this->assertNull($project->fresh());
    }

    public function test_the_close_job_ends_allocations_of_projects_that_are_gone_or_moved(): void
    {
        $this->setUpBilling();
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $kept = $this->billedProject('kept', $region, $owner);
        $gone = $this->billedProject('gone', $region, $owner);
        $moved = $this->billedProject('moved', $region, $owner);
        $keep = $this->allocation($kept, 1000, 1024, '2026-10-01');
        $orphan = $this->allocation($gone, 1000, 1024, '2026-10-01');
        $elsewhere = $this->allocation($moved, 1000, 1024, '2026-10-20');
        $pending = $this->allocation($gone, 2000, 1024, '2026-10-01', null, FixedRequest::PENDING);
        // Removed and moved without the model hooks (raw queries, an earlier release)
        DB::table('projects')->where('id', $gone->id)->delete();
        DB::table('projects')->where('id', $moved->id)->update(['knative_region_id' => $this->billingRegion('other')->id]);

        app(BillService::class)->ensureOpenBills();

        $this->assertSame([FixedRequest::APPROVED, null], [$keep->fresh()->status, $keep->fresh()->active_until]);
        // Yesterday, at the earliest on the first day
        $this->assertSame([FixedRequest::ENDED, '2026-10-19'], [$orphan->fresh()->status, $orphan->fresh()->active_until->toDateString()]);
        $this->assertSame([FixedRequest::ENDED, '2026-10-20'], [$elsewhere->fresh()->status, $elsewhere->fresh()->active_until->toDateString()]);
        $this->assertSame(FixedRequest::CANCELLED, $pending->fresh()->status);
    }
}
