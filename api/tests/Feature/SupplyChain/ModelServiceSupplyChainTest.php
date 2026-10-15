<?php

namespace Tests\Feature\SupplyChain;

use App\Events\SupplyChainAuditEvent;
use App\Jobs\Ai\DeployModelServiceJob;
use App\Jobs\StartSupplyChainJob;
use App\Models\AiModelService;
use App\Models\AuditEvent;
use App\Models\ImageSecurityReport;
use App\Models\Project;
use App\Models\RegionSupplyChainSetting;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Services\Residency\RegionPolicy;
use App\Services\SupplyChain\AdmissionManifests;
use App\Services\SupplyChain\RescanService;
use App\Services\SupplyChain\SupplyChainPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsAiFixtures;
use Tests\Concerns\FakesSupplyChainCluster;
use Tests\TestCase;

/**
 * AI model services and the supply chain: data residency at service create / deploy, model
 * snapshots and model bindings; the service image as an external image (SBOM + scan when the
 * region scans external images), the policy gate on it, re-scans, admission exemptions of the
 * engine images, the report endpoint.
 */
class ModelServiceSupplyChainTest extends TestCase
{
    use ActsWithRoles, BuildsAiFixtures, FakesSupplyChainCluster, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSupplyChainCluster();
        Event::fake([SupplyChainAuditEvent::class]);
        Bus::fake([StartSupplyChainJob::class, DeployModelServiceJob::class]);
        $this->owner = $this->userWithRole('owner', ['username' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    protected function tearDown(): void
    {
        $this->tearDownSupplyChain();
        parent::tearDown();
    }

    private function region(string $name, ?string $location = null)
    {
        $region = $this->gpuRegion(null, ['name' => $name, 'kubeconfig' => $this->scKubeconfig()]);
        if ($location) {
            $this->scSettings($region, ['data_location' => $location]);
        }

        return $region;
    }

    private function pinTenantTo(string $regionId): void
    {
        RegionPolicy::resolveTenantUsing(fn () => 'acme');
        TenantSecurityPolicy::create(['tenant_id' => 'acme', 'residency_enforced' => true, 'allowed_region_ids' => [$regionId]]);
    }

    // ── Residency ───────────────────────────────────────────────────────────

    public function test_model_services_follow_the_data_residency(): void
    {
        $allowed = $this->region('istanbul');
        $other = $this->region('frankfurt');
        $this->pinTenantTo($allowed->id);
        $model = $this->hubModel();

        $this->postJson('/api/model-services', ['ai_model_id' => $model->id, 'knative_region_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'region_not_allowed')
            ->assertJsonPath('action', 'model_service.deploy');
        $this->assertSame(0, AiModelService::count());

        // An existing service in a region the policy no longer allows: refused, left stopped
        $service = $this->modelService($model, $other, ['status' => AiModelService::STATUS_STOPPED]);
        $this->postJson("/api/model-services/{$service->id}/deploy")->assertStatus(422)->assertJsonPath('code', 'region_not_allowed');
        $this->assertSame(AiModelService::STATUS_STOPPED, $service->fresh()->status);
        $this->assertStringContainsString('frankfurt', (string) $service->fresh()->status_message);
        Bus::assertNotDispatched(DeployModelServiceJob::class);
    }

    public function test_model_weights_and_bindings_follow_the_data_residency(): void
    {
        $allowed = $this->region('istanbul');
        $other = $this->region('frankfurt');
        $this->pinTenantTo($allowed->id);
        $model = $this->hubModel();

        $this->postJson("/api/models/{$model->id}/snapshots", ['knative_region_id' => $other->id])
            ->assertStatus(422)->assertJsonPath('action', 'model.snapshot');

        $service = $this->modelService($model, $other);
        $project = Project::create(['name' => 'consumer', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $allowed->id])->saveQuietly();
        $this->postJson("/api/projects/{$project->id}/models", ['ai_model_service_id' => $service->id, 'alias' => 'EMBED'])
            ->assertStatus(422)
            ->assertJsonPath('action', 'binding.create')
            ->assertJsonValidationErrors('ai_model_service_id');
    }

    // ── Pipeline ────────────────────────────────────────────────────────────

    public function test_the_service_image_is_scanned_like_a_caas_image(): void
    {
        $region = $this->region('eu-1');
        $service = $this->modelService($this->hubModel(), $region)->load('model', 'region');

        $this->assertNull(app(SupplyChainPipeline::class)->queueForModelService($service), 'off until the region scans external images');

        $this->scSettings($region, ['scan_external_images' => true]);
        $report = app(SupplyChainPipeline::class)->queueForModelService($service->fresh(['model', 'region']));

        $this->assertSame(ImageSecurityReport::SUBJECT_MODEL_SERVICE, $report->subject_type);
        $this->assertSame(ImageSecurityReport::KIND_EXTERNAL, $report->image_kind);
        $this->assertSame('ghcr.io/huggingface/text-embeddings-inference:cpu-1.5', $report->image_ref, 'the TEI CPU engine image');
        $this->assertSame(['sbom', 'scan'], $report->steps);
        $this->assertNull($report->project_id);

        // An own image replaces the engine's
        $service->update(['image' => 'ghcr.io/acme/tei-patched:1']);
        $this->assertSame('ghcr.io/acme/tei-patched:1', app(SupplyChainPipeline::class)->modelServiceImage($service->fresh(['model', 'region'])));
    }

    public function test_the_gate_waits_for_the_scan_of_the_service_image(): void
    {
        $region = $this->region('eu-1');
        $this->scSettings($region, ['scan_external_images' => true, 'gate_mode' => 'enforce', 'gate_require_scan' => true]);
        $service = $this->modelService($this->hubModel(), $region, ['status' => AiModelService::STATUS_STOPPED]);

        $this->postJson("/api/model-services/{$service->id}/deploy")
            ->assertStatus(422)
            ->assertJsonPath('code', 'supply_chain_scan_pending');

        Bus::assertDispatched(StartSupplyChainJob::class);
        Bus::assertNotDispatched(DeployModelServiceJob::class);
        $this->assertSame(AiModelService::STATUS_STOPPED, $service->fresh()->status);
        $this->assertNotNull(ImageSecurityReport::forModelService($service));
    }

    public function test_findings_above_the_limit_block_and_an_override_lets_the_service_through(): void
    {
        $region = $this->region('eu-1');
        $this->scSettings($region, ['scan_external_images' => true, 'gate_mode' => 'enforce', 'gate_max_critical' => 0]);
        $service = $this->modelService($this->hubModel(), $region, ['status' => AiModelService::STATUS_STOPPED]);
        ImageSecurityReport::create([
            'subject_type' => ImageSecurityReport::SUBJECT_MODEL_SERVICE, 'subject_id' => $service->id, 'knative_region_id' => $region->id,
            'image_kind' => 'external', 'image_ref' => 'ghcr.io/huggingface/text-embeddings-inference:cpu-1.5',
            'status' => 'completed', 'sbom_status' => 'ok', 'scan_status' => 'ok', 'critical_count' => 3,
        ]);

        $this->postJson("/api/model-services/{$service->id}/deploy")
            ->assertStatus(422)
            ->assertJsonPath('code', 'supply_chain_policy')
            ->assertJsonPath('violations.0.rule', 'critical')
            ->assertJsonPath('can_override', true);
        $this->assertSame('denied', AuditEvent::query()->where('action', 'deploy.policy_blocked')->sole()->result);

        $this->postJson("/api/model-services/{$service->id}/deploy", ['override_policy' => true, 'override_reason' => 'internal network only'])
            ->assertStatus(202);
        Bus::assertDispatched(DeployModelServiceJob::class);

        $override = AuditEvent::query()->where('action', 'deploy.policy_overridden')->sole();
        $this->assertSame('model_service', $override->target_type);
        $this->assertSame($service->id, $override->target_id);
        $this->assertSame('internal network only', $override->metadata['reason']);
    }

    public function test_gate_off_changes_nothing_for_model_services(): void
    {
        $region = $this->region('eu-1');
        $service = $this->modelService($this->hubModel(), $region, ['status' => AiModelService::STATUS_STOPPED]);

        $this->postJson("/api/model-services/{$service->id}/deploy")->assertStatus(202);
        Bus::assertNotDispatched(StartSupplyChainJob::class);
    }

    public function test_running_services_are_rescanned_and_reports_follow_the_service(): void
    {
        $region = $this->region('eu-1');
        $this->scSettings($region, ['scan_external_images' => true, 'rescan_interval_hours' => 24]);
        $service = $this->modelService($this->hubModel(), $region);
        ImageSecurityReport::create([
            'subject_type' => ImageSecurityReport::SUBJECT_MODEL_SERVICE, 'subject_id' => $service->id, 'knative_region_id' => $region->id,
            'image_kind' => 'external', 'image_ref' => 'ghcr.io/huggingface/text-embeddings-inference:cpu-1.5',
            'status' => 'completed', 'sbom_status' => 'ok', 'scan_status' => 'ok', 'scanned_at' => now()->subDays(2),
        ]);

        $this->assertSame(1, app(RescanService::class)->rescanDue());
        $this->assertSame(['scan'], ImageSecurityReport::forModelService($service)->steps);

        $this->getJson("/api/model-services/{$service->id}/security")->assertOk()
            ->assertJsonPath('data.image', 'ghcr.io/huggingface/text-embeddings-inference:cpu-1.5')
            ->assertJsonPath('data.scanned', true)
            ->assertJsonPath('data.current', true)
            ->assertJsonPath('data.report.subject_type', 'model_service');

        $service->delete();
        $this->assertNull(ImageSecurityReport::forModelService($service->id));
    }

    public function test_engine_images_are_system_images_of_the_admission_policy(): void
    {
        $region = $this->region('eu-1');

        $globs = AdmissionManifests::systemGlobs($region, RegionSupplyChainSetting::forRegion($region));

        $this->assertContains('index.docker.io/vllm/vllm-openai:*', $globs);
        $this->assertContains('ghcr.io/huggingface/text-embeddings-inference@*', $globs);
        $this->assertContains('ghcr.io/huggingface/text-generation-inference:*', $globs);
    }
}
