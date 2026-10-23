<?php

namespace Tests\Feature\Billing;

use App\Models\FaasConfig;
use App\Models\ImportedHistoryRecord;
use App\Models\OrgTransfer;
use App\Models\Project;
use App\Models\User;
use App\Services\OrgTransfer\ImportChoices;
use App\Services\OrgTransfer\OrgExporter;
use App\Services\OrgTransfer\OrgImporter;
use App\Services\OrgTransfer\OrgTransferPaths;
use App\Services\OrgTransfer\Package\PackageReader;
use App\Services\OrgTransfer\Sections\SectionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Modules\Billing\Models\Bill;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * The organization's bills in its run history (docs/org-export.md#run-history): exported with
 * include_history — project and account bills, never the platform's bills to tenants — and
 * imported read-only into imported_history where billing is on: never into billing_bills, so
 * nothing is recalculated, closed or charged again. Billing off on the target: they stay in the
 * package, with a warning.
 */
class BillingHistoryTransferTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        config(['org_transfer.compression' => 'none', 'secrets.storage' => 'database']);
        Queue::fake();
        SectionRegistry::reset();
        $this->setUpBilling();
        $this->owner = $this->userWithRole('owner');
        $this->actingAs($this->owner, 'sanctum');
    }

    protected function tearDown(): void
    {
        SectionRegistry::reset();
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bill(array $attributes): Bill
    {
        return Bill::query()->create($attributes + [
            'kind' => Bill::PROJECT, 'period' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'currency' => 'USD', 'status' => Bill::CLOSED, 'line_items' => [['label' => 'vCPU', 'amount' => 10]], 'metered_lines' => [],
            'fixed_total' => 0, 'usage_total' => 10, 'total' => 10, 'metered_cost' => 10, 'closed_at' => now(),
        ]);
    }

    /** @return array{0: Project, 1: OrgTransfer} the source project and an import of its package, planned */
    private function exportAndPlan(): array
    {
        $region = $this->billingRegion();
        $project = Project::create(['name' => 'api', 'service_type' => 'faas', 'runtime' => 'python', 'created_by' => (string) $this->owner->id]);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30]);

        $this->bill(['subject_key' => Bill::projectKey($project->id), 'project_id' => $project->id, 'project_name' => 'api', 'paid' => true, 'payment_reference' => 'INV-7']);
        $this->bill(['subject_key' => Bill::projectKey($project->id), 'project_id' => $project->id, 'project_name' => 'api', 'period' => '2026-10', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => Bill::OPEN, 'closed_at' => null]);
        $this->bill(['kind' => Bill::TENANT, 'subject_key' => 'tenant:acme', 'tenant_id' => 'acme']);

        $export = OrgTransfer::query()->create(['owner_key' => 'platform', 'kind' => 'export', 'status' => 'queued', 'options' => ['include_history' => true], 'destination' => ['type' => 'download'], 'created_by' => (string) $this->owner->id]);
        app(OrgExporter::class)->run($export, null);
        $export->refresh();
        $this->assertSame('completed', $export->status, (string) $export->message);
        $this->assertSame(2, (new PackageReader)->manifest(OrgTransferPaths::package($export))['contents']['history_bills'], 'not the platform\'s bill to a tenant');

        $import = OrgTransfer::query()->create(['owner_key' => 'platform', 'kind' => 'import', 'status' => 'verifying', 'upload_size' => $export->package_size, 'uploaded_bytes' => $export->package_size, 'created_by' => (string) $this->owner->id]);
        OrgTransferPaths::ensure(OrgTransferPaths::dir($import));
        copy(OrgTransferPaths::package($export), OrgTransferPaths::package($import));
        app(OrgImporter::class)->verify($import, ImportChoices::from(['renames' => [$project->id => 'api-copy']]));

        return [$project, $import->fresh()];
    }

    private function apply(OrgTransfer $import): OrgTransfer
    {
        $import->update(['status' => OrgTransfer::STATUS_APPLYING]);
        app(OrgImporter::class)->apply($import, ImportChoices::from((array) $import->options), $this->owner);

        return $import->fresh();
    }

    public function test_bills_travel_as_read_only_history(): void
    {
        [, $import] = $this->exportAndPlan();
        $this->assertSame(2, $import->plan['history']['bills']);
        $this->assertTrue($import->plan['history']['bills_imported']);
        $bills = Bill::query()->count();

        $import = $this->apply($import);

        $this->assertSame('completed', $import->status, json_encode($import->report));
        $copy = Project::query()->where('slug', 'api-copy')->firstOrFail();
        $rows = ImportedHistoryRecord::query()->where('org_transfer_id', $import->id)->where('kind', 'bill')->orderBy('period_date')->get();
        $this->assertCount(2, $rows);
        $this->assertSame((string) $copy->id, (string) $rows[0]->project_id);
        $this->assertSame('INV-7', $rows[0]->data['payment_reference']);
        $this->assertSame('open', $rows[1]->data['status']);
        $this->assertSame($bills, Bill::query()->count(), 'no bill was created');
        $this->getJson("/api/org-transfers/{$import->id}/history?kind=bill")->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_with_billing_off_the_bills_stay_in_the_package(): void
    {
        [, $import] = $this->exportAndPlan();
        config(['billing.enabled' => false]);

        $plan = app(OrgImporter::class)->plan($import, ImportChoices::from((array) $import->options));
        $this->assertContains('billing_off', array_column($plan['issues'], 'code'));
        $this->assertFalse($plan['history']['bills_imported']);

        $import = $this->apply($import);
        $item = collect($import->report['items'])->firstWhere('source', 'bills');
        $this->assertSame('skipped', $item['status']);
        $this->assertSame(0, ImportedHistoryRecord::query()->where('kind', 'bill')->count());
    }
}
