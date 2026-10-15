<?php

namespace Tests\Feature\Ai;

use App\Jobs\Ai\CheckModelServiceReadyJob;
use App\Jobs\Ai\DeployModelServiceJob;
use App\Models\AiModel;
use App\Models\AiModelService;
use App\Models\AiModelSnapshot;
use App\Models\User;
use App\Services\Ai\Gpu\GpuSupportManifests;
use App\Services\Ai\Models\ModelServiceDeployer;
use App\Services\Ai\Models\ModelServiceManifests;
use App\Support\DeployGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Deploying a model service into a region (DeployModelServiceJob → ModelServiceDeployer::deploy):
 * online (Hub cache, no egress policy) and offline (the ready snapshot, egress closed by a
 * NetworkPolicy) on the model volume, the Knative Service applied and readiness polled; demo
 * regions are ready at once; a blocked context, a missing or unusable region, an online service
 * of a model that is not on the Hub and a refused apply fail the service with the reason. Its
 * pod logs come from the engine container.
 */
class ModelServiceDeployTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        DeployGate::setResolver(null);
        parent::tearDown();
    }

    /** A cluster whose Knative pod spec features are in force already (no wait for the webhook). */
    private function cluster(array $routes = []): void
    {
        $this->fakeCluster($routes + [
            'GET /api/v1/namespaces/knative-serving/configmaps/config-features' => ['data' => GpuSupportManifests::knativeFeatures()],
        ]);
    }

    private function service(array $attributes = [], ?AiModel $model = null, array $region = []): AiModelService
    {
        $region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()] + $region);

        return $this->modelService($model ?? $this->hubModel(), $region, array_merge(['status' => AiModelService::STATUS_DEPLOYING], $attributes));
    }

    private function deploy(AiModelService $service): AiModelService
    {
        (new DeployModelServiceJob($service->id))->handle(app(ModelServiceDeployer::class));

        return $service->fresh();
    }

    public function test_an_online_service_runs_from_the_hub_cache_without_an_egress_policy(): void
    {
        $this->cluster();
        $service = $this->service(['mode' => 'online']);

        $service = $this->deploy($service);

        $this->assertSame(AiModelService::STATUS_DEPLOYING, $service->status);
        $this->assertNull($service->status_message);
        $this->assertNotNull($service->deployed_at);
        $this->assertNull($service->snapshot_id);

        $this->assertNotEmpty($this->k8sCallsTo('POST', '/namespaces/fn/persistentvolumeclaims'), 'the model volume first');
        $ksvc = $this->k8sCallsTo('PATCH', "/apis/serving.knative.dev/v1/namespaces/fn/services/{$service->service_name}");
        $this->assertCount(1, $ksvc);
        $this->assertSame($service->service_name, $ksvc[0]['body']['metadata']['name']);
        // a former offline policy goes; none is applied
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', '/networkpolicies/'.ModelServiceManifests::networkPolicyName($service)));
        $this->assertSame([], $this->k8sCallsTo('PATCH', '/networkpolicies/'));
        Queue::assertPushed(CheckModelServiceReadyJob::class, fn ($job) => $job->serviceId === $service->id);
    }

    public function test_an_offline_service_mounts_the_ready_snapshot_behind_an_egress_policy(): void
    {
        $this->cluster();
        $service = $this->service(['mode' => 'offline', 'engine' => 'transformers']);
        $snapshot = AiModelSnapshot::create([
            'ai_model_id' => $service->ai_model_id, 'knative_region_id' => $service->knative_region_id, 'source' => 'hub',
            'revision' => str_repeat('a', 40), 'path' => 'snapshots/default/all-minilm-l6-v2/'.str_repeat('a', 40), 'status' => AiModelSnapshot::STATUS_READY,
        ]);

        $service = $this->deploy($service);

        $this->assertSame(AiModelService::STATUS_DEPLOYING, $service->status);
        $this->assertSame($snapshot->id, $service->snapshot_id);
        $policy = $this->k8sCallsTo('PATCH', '/networkpolicies/'.ModelServiceManifests::networkPolicyName($service));
        $this->assertCount(1, $policy);
        $this->assertSame(['Egress'], $policy[0]['body']['spec']['policyTypes']);
        $template = $this->k8sCallsTo('PATCH', "/services/{$service->service_name}")[0]['body']['spec']['template']['spec'];
        $mount = collect($template['containers'][0]['volumeMounts'])->firstWhere('mountPath', '/models/model');
        $this->assertSame($snapshot->path, $mount['subPath']);
        $this->assertTrue($mount['readOnly']);
    }

    public function test_a_refused_apply_fails_the_service_with_the_reason(): void
    {
        $service = $this->service(['mode' => 'online']);
        $this->cluster([
            "PATCH /apis/serving.knative.dev/v1/namespaces/fn/services/{$service->service_name}" => [['kind' => 'Status', 'message' => 'admission webhook denied the request'], 400],
        ]);

        $service = $this->deploy($service);

        $this->assertSame(AiModelService::STATUS_FAILED, $service->status);
        $this->assertSame(__('ai.models.apply_failed', ['kind' => 'Knative Service']), $service->status_message);
        Queue::assertNotPushed(CheckModelServiceReadyJob::class);
    }

    public function test_a_demo_region_runs_the_service_at_once(): void
    {
        Http::fake();
        $service = $this->deploy($this->service([], null, ['is_demo' => true]));

        $this->assertSame(AiModelService::STATUS_READY, $service->status);
        $this->assertNotNull($service->deployed_at);
        Http::assertNothingSent();
    }

    public function test_services_that_can_not_run_fail_with_the_reason(): void
    {
        Http::fake();
        $model = $this->hubModel();

        // a region that is not active
        $inactive = $this->deploy($this->service([], $model, ['is_active' => false]));
        $this->assertSame(AiModelService::STATUS_FAILED, $inactive->status);
        $this->assertSame(__('ai.models.region_unavailable', ['region' => $inactive->region->name]), $inactive->status_message);

        // online needs a Hub model
        $uploaded = AiModel::create(['name' => 'local-bert', 'source' => 'upload', 'task' => 'fill-mask']);
        $online = $this->deploy($this->service(['mode' => 'online'], $uploaded));
        $this->assertSame(__('ai.models.online_needs_hub'), $online->status_message);

        // the region is gone
        $orphan = $this->service([], $model);
        $orphan->setRelation('region', null);
        app(ModelServiceDeployer::class)->deploy($orphan);
        $this->assertSame(__('ai.models.region_missing'), $orphan->fresh()->status_message);

        // nothing new while the organization is being deleted
        DeployGate::setResolver(fn () => 'The organization is being deleted.');
        $blocked = $this->deploy($this->service([], $model));
        $this->assertSame(AiModelService::STATUS_FAILED, $blocked->status);
        $this->assertSame('The organization is being deleted.', $blocked->status_message);

        Http::assertNothingSent();
    }

    public function test_the_logs_of_a_service_come_from_its_engine_containers(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $service = $this->service(['status' => AiModelService::STATUS_READY]);
        $this->cluster([
            'GET /api/v1/namespaces/fn/pods' => ['items' => [
                ['metadata' => ['name' => 'm-a'], 'status' => ['phase' => 'Running', 'conditions' => [['type' => 'Ready', 'status' => 'True']]]],
                ['metadata' => ['name' => 'm-b'], 'status' => ['phase' => 'Pending']],
            ]],
            'GET /api/v1/namespaces/fn/pods/m-a/log' => fn () => Http::response("Ready\n"),
            'GET /api/v1/namespaces/fn/pods/m-b/log' => fn () => Http::response(''),
        ]);

        $logs = app(ModelServiceDeployer::class)->logs($service, 5000);

        $this->assertSame([
            ['name' => 'm-a', 'phase' => 'Running', 'ready' => true, 'logs' => "Ready\n"],
            ['name' => 'm-b', 'phase' => 'Pending', 'ready' => false, 'logs' => ''],
        ], $logs);
        $this->assertStringContainsString('labelSelector='.urlencode(AiModelService::LABEL.'='.$service->id), $this->k8sCallsTo('GET', '/namespaces/fn/pods')[0]['query']);
        $log = $this->k8sCallsTo('GET', '/pods/m-a/log')[0]['query'];
        $this->assertStringContainsString('container=model', $log);
        $this->assertStringContainsString('tailLines=2000', $log, 'at most 2000 lines');
    }
}
