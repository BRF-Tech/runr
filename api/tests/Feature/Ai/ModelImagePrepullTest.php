<?php

namespace Tests\Feature\Ai;

use App\Jobs\Ai\PrepullModelImagesJob;
use App\Models\User;
use App\Services\Ai\Gpu\GpuScheduling;
use App\Services\Ai\Models\ModelImagePrepull;
use App\Services\Ai\Models\ModelStorage;
use App\Services\KubernetesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Model service images are pulled onto the nodes before a service needs them: when the region's
 * model storage is set up (the CPU hf-serve image where projects run) and when a service is
 * created or deployed (its engine image, on GPU nodes for a GPU service). PrepullModelImagesJob
 * applies the crictl DaemonSet of the region pre-pull, waits for every node and removes it.
 */
class ModelImagePrepullTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function prepull(): ModelImagePrepull
    {
        return app(ModelImagePrepull::class);
    }

    public function test_setting_up_model_storage_pulls_hf_serve_where_projects_run(): void
    {
        $this->fakeCluster();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig(), 'selected_workers' => ['worker-1']]);

        $this->putJson("/api/knative-regions/{$region->id}/model-storage", ['size_gi' => 200])->assertOk();

        Queue::assertPushed(PrepullModelImagesJob::class, fn (PrepullModelImagesJob $job) => $job->regionId === $region->id
            && $job->attempt === 0
            && $job->images === ['ghcr.io/brf-tech/hf-serve:1.1.0']
            && $job->placement === [
                'nodeSelector' => ['runr.dev/dedicated' => 'projects'],
                'tolerations' => [['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule']],
            ]
            && str_starts_with($job->name, 'runr-model-prepull-'));

        // the same images on the same nodes: one pre-pull at a time
        $this->putJson("/api/knative-regions/{$region->id}/model-storage", ['size_gi' => 300])->assertOk();
        Queue::assertPushed(PrepullModelImagesJob::class, 1);
    }

    public function test_a_regions_first_model_volume_pulls_hf_serve_too(): void
    {
        $this->fakeCluster();
        $region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()]);

        app(ModelStorage::class)->ensure($region, new KubernetesClient($region->kubeconfig), 'fn');

        $this->assertNotEmpty($this->k8sCallsTo('POST', '/namespaces/fn/persistentvolumeclaims'));
        Queue::assertPushed(PrepullModelImagesJob::class, fn (PrepullModelImagesJob $job) => $job->images === ['ghcr.io/brf-tech/hf-serve:1.1.0'] && $job->placement === []);
    }

    public function test_services_pull_their_engine_image_on_the_nodes_they_run_on(): void
    {
        $region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()]);
        $model = $this->hubModel();

        // TEI on CPU, online: the engine and the cache init image, on any schedulable node
        $this->prepull()->forService($this->modelService($model, $region, ['engine' => 'tei']));
        Queue::assertPushed(PrepullModelImagesJob::class, fn ($job) => $job->images === ['ghcr.io/huggingface/text-embeddings-inference:cpu-1.9.4', config('ai.images.busybox')] && $job->placement === []);

        // offline hf-serve: no init container
        $this->prepull()->forService($this->modelService($model, $region, ['engine' => 'transformers', 'mode' => 'offline']));
        Queue::assertPushed(PrepullModelImagesJob::class, fn ($job) => $job->images === ['ghcr.io/brf-tech/hf-serve:1.1.0']);

        // a GPU service of a GPU type: the CUDA image on GPU nodes of that type (8.9 → 89- TEI)
        $this->prepull()->forService($this->modelService($model, $region, ['engine' => 'tei', 'device' => 'gpu', 'gpu' => ['count' => 1, 'type' => 'NVIDIA-L4']]));
        Queue::assertPushed(PrepullModelImagesJob::class, function ($job) {
            $terms = $job->placement['affinity']['nodeAffinity']['requiredDuringSchedulingIgnoredDuringExecution']['nodeSelectorTerms'] ?? [];

            return $job->images[0] === 'ghcr.io/huggingface/text-embeddings-inference:89-1.9.4'
                && $job->placement['nodeSelector'] === ['nvidia.com/gpu.product' => 'NVIDIA-L4']
                && $job->placement['tolerations'] === GpuScheduling::TOLERATIONS
                && $terms[0]['matchExpressions'][0] === ['key' => 'nvidia.com/gpu.count', 'operator' => 'Exists']
                && $terms[1]['matchExpressions'][0] === ['key' => 'nvidia.com/gpu.present', 'operator' => 'In', 'values' => ['true']];
        });
        Queue::assertPushed(PrepullModelImagesJob::class, 3);

        // custom images (their own credentials), demo regions and regions without a cluster: nothing
        $this->assertNull($this->prepull()->forService($this->modelService($model, $region, ['engine' => 'custom', 'image' => 'ghcr.io/acme/server:2'])));
        $demo = $this->gpuRegion(null, ['is_demo' => true, 'kubeconfig' => $this->fakeKubeconfig()]);
        $this->assertNull($this->prepull()->forService($this->modelService($model, $demo, ['engine' => 'tei'])));
        $this->assertNull($this->prepull()->forStorage($this->gpuRegion()));
        Queue::assertPushed(PrepullModelImagesJob::class, 3);
    }

    public function test_images_are_the_references_the_pods_pull(): void
    {
        // an air-gapped region: images named without a registry host come through its registry
        config(['ai.images.hf_serve' => 'runr/hf-serve:1.1.0', 'ai.images.busybox' => 'library/busybox:1.37']);
        $region = $this->gpuRegion(null, [
            'kubeconfig' => $this->fakeKubeconfig(), 'registry_mode' => 'internal', 'registry_status' => 'ready',
            'registry_address' => '10.43.0.5:5000', 'registry_namespace' => 'runr-registry',
        ]);

        $name = $this->prepull()->forStorage($region);
        $job = Queue::pushed(PrepullModelImagesJob::class)->first();
        $this->assertSame($name, $job->name);
        $this->assertSame(['10.43.0.5:5000/runr/hf-serve:1.1.0'], $job->images);

        $ds = ModelImagePrepull::daemonSet($region, $name, $job->images, []);
        $spec = $ds['spec']['template']['spec'];
        $this->assertSame($name, $ds['metadata']['name']);
        $this->assertSame('model-prepull', $ds['metadata']['labels']['app.kubernetes.io/component']);
        $this->assertSame(['app' => $name], $ds['spec']['selector']['matchLabels']);
        $this->assertSame(['pull-all'], array_column($spec['initContainers'], 'name'), 'no sysctl step');
        $this->assertStringContainsString("crictl pull '10.43.0.5:5000/runr/hf-serve:1.1.0'", $spec['initContainers'][0]['command'][8]);
        $this->assertSame(['10.43.0.5:5000/library/busybox:1.37'], array_values(array_unique(array_merge(array_column($spec['initContainers'], 'image'), array_column($spec['containers'], 'image')))), 'the helper through the registry too');
        $this->assertArrayNotHasKey('tolerations', $spec, 'schedulable nodes only');
        $this->assertSame('runr-registry', ModelImagePrepull::namespace($region), 'next to the region pre-pull');

        $region->update(['registry_mode' => 'external']);
        $this->assertSame('runr-gpu', ModelImagePrepull::namespace($region->fresh()), 'else the AI namespace');
    }

    public function test_the_job_applies_the_daemonset_waits_for_every_node_and_removes_it(): void
    {
        $region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()]);
        $images = ['ghcr.io/brf-tech/hf-serve:1.1.0'];
        $status = ['desiredNumberScheduled' => 3, 'numberReady' => 1];
        $this->fakeCluster([
            'GET /apis/apps/v1/namespaces/runr-gpu/daemonsets/runr-model-prepull-abc' => function () use (&$status) {
                return ['metadata' => ['name' => 'runr-model-prepull-abc'], 'status' => $status];
            },
        ]);

        (new PrepullModelImagesJob($region->id, 'runr-model-prepull-abc', $images))->handle();

        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/api/v1/namespaces/runr-gpu'), 'the AI namespace is created when missing');
        $apply = $this->k8sCallsTo('PATCH', '/apis/apps/v1/namespaces/runr-gpu/daemonsets/runr-model-prepull-abc');
        $this->assertCount(1, $apply);
        $this->assertStringContainsString("crictl pull 'ghcr.io/brf-tech/hf-serve:1.1.0'", $apply[0]['body']['spec']['template']['spec']['initContainers'][0]['command'][8]);
        Queue::assertPushed(PrepullModelImagesJob::class, fn ($job) => $job->attempt === 1 && $job->images === $images);

        // one of three nodes has the image: wait
        (new PrepullModelImagesJob($region->id, 'runr-model-prepull-abc', $images, [], 1))->handle();
        Queue::assertPushed(PrepullModelImagesJob::class, fn ($job) => $job->attempt === 2);
        $this->assertSame([], $this->k8sCallsTo('DELETE', '/daemonsets/'));

        // every node: removed
        $status = ['desiredNumberScheduled' => 3, 'numberReady' => 3];
        (new PrepullModelImagesJob($region->id, 'runr-model-prepull-abc', $images, [], 2))->handle();
        $this->assertCount(1, $this->k8sCallsTo('DELETE', '/apis/apps/v1/namespaces/runr-gpu/daemonsets/runr-model-prepull-abc'));
        Queue::assertNotPushed(PrepullModelImagesJob::class, fn ($job) => $job->attempt === 3);

        // the deadline: removed whatever it pulled
        $status = ['desiredNumberScheduled' => 3, 'numberReady' => 2];
        (new PrepullModelImagesJob($region->id, 'runr-model-prepull-abc', $images, [], PrepullModelImagesJob::maxAttempts()))->handle();
        $this->assertCount(2, $this->k8sCallsTo('DELETE', '/daemonsets/runr-model-prepull-abc'));
        $this->assertSame(90, PrepullModelImagesJob::maxAttempts(), '30 minutes every 20 s');
    }

    public function test_a_pre_pull_that_cannot_start_may_be_tried_again(): void
    {
        $region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()]);
        $this->fakeCluster([
            'GET /api/v1/namespaces/runr-gpu' => ['metadata' => ['name' => 'runr-gpu']],
            'PATCH /apis/apps/v1/namespaces/runr-gpu/daemonsets/*' => [['kind' => 'Status', 'message' => 'forbidden'], 403],
        ]);

        $name = $this->prepull()->forStorage($region);
        $this->assertNull($this->prepull()->forStorage($region), 'running');

        (new PrepullModelImagesJob($region->id, $name, ['ghcr.io/brf-tech/hf-serve:1.1.0']))->handle();

        $this->assertSame([], $this->k8sCallsTo('PATCH', '/api/v1/namespaces/runr-gpu'), 'the namespace exists');
        Queue::assertNotPushed(PrepullModelImagesJob::class, fn ($job) => $job->attempt === 1);
        $this->assertSame($name, $this->prepull()->forStorage($region), 'free again');

        // a region that is gone: nothing to do
        $calls = count($this->k8sCalls);
        (new PrepullModelImagesJob('01missing0000000000000000', $name, []))->handle();
        $this->assertCount($calls, $this->k8sCalls);
    }
}
