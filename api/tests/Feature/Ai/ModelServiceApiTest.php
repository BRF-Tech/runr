<?php

namespace Tests\Feature\Ai;

use App\Jobs\Ai\DeployModelServiceJob;
use App\Jobs\Ai\PrepullModelImagesJob;
use App\Jobs\Ai\StartModelSnapshotJob;
use App\Models\AiModel;
use App\Models\AiModelBinding;
use App\Models\AiModelService;
use App\Models\AiModelSnapshot;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\Gpu\GpuScheduling;
use App\Services\Ai\Models\ModelServiceDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Models and model services over the API: one-click deploy of a Hub model (model added and
 * pinned on first use, engine / device / size resolved, deploy queued), the checks that keep a
 * service from running (engine vs task / device, online without a Hub model, custom without
 * image, GPU region problems), update / stop / delete, the playground allow-list, the model
 * library (uploaded / imported models, delete refused while services use a model) and snapshots.
 */
class ModelServiceApiTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.huggingface.cache_seconds' => 0]);
        $this->actingAs(User::factory()->create(), 'sanctum');
        Queue::fake();
    }

    private function fakeHubModel(): void
    {
        Http::fake([
            'https://huggingface.co/api/models/sentence-transformers/all-MiniLM-L6-v2*' => Http::response([
                'id' => 'sentence-transformers/all-MiniLM-L6-v2', 'sha' => str_repeat('c', 40),
                'pipeline_tag' => 'sentence-similarity', 'library_name' => 'sentence-transformers',
                'tags' => ['text-embeddings-inference'], 'siblings' => [['rfilename' => 'model.safetensors', 'size' => 90_000_000]],
            ]),
            'https://huggingface.co/sentence-transformers/*' => Http::response("# MiniLM\nSentence embeddings."),
        ]);
    }

    private function region(array $attributes = [])
    {
        return $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()] + $attributes);
    }

    public function test_one_click_deploy_of_a_hub_model(): void
    {
        $this->fakeHubModel();
        $region = $this->region();

        $response = $this->postJson('/api/model-services', [
            'hub_id' => 'sentence-transformers/all-MiniLM-L6-v2',
            'knative_region_id' => $region->id,
            'mode' => 'online',
        ])->assertCreated();

        $model = AiModel::firstOrFail();
        $this->assertSame(str_repeat('c', 40), $model->revision_sha, 'pinned to the commit of main');
        $this->assertSame('sentence-similarity', $model->task);
        $this->assertSame('Sentence embeddings.', trim(substr((string) $model->card_summary, strpos((string) $model->card_summary, "\n"))));

        $response->assertJsonPath('engine', 'tei')
            ->assertJsonPath('device', 'cpu')
            ->assertJsonPath('mode', 'online')
            ->assertJsonPath('api', 'tei')
            ->assertJsonPath('status', AiModelService::STATUS_DEPLOYING)
            ->assertJsonPath('playground.kind', 'embeddings')
            ->assertJsonPath('url', 'http://m-'.$model->name.'.fn.svc.cluster.local')
            ->assertJsonPath('openai_base', 'http://m-'.$model->name.'.fn.svc.cluster.local/v1');

        Queue::assertPushed(DeployModelServiceJob::class, fn ($job) => $job->serviceId === $response->json('id'));

        // the same Hub model + revision is reused
        $this->postJson('/api/model-services', ['hub_id' => 'sentence-transformers/all-MiniLM-L6-v2', 'knative_region_id' => $region->id, 'name' => 'second'])->assertCreated();
        $this->assertSame(1, AiModel::count());
        $this->assertSame(2, AiModelService::count());
        $this->assertSame(90_000_000, $model->fresh()->size_bytes);
        $this->assertSame('safetensors', $model->fresh()->metadata['weights_format']);
    }

    public function test_a_hub_model_sized_from_its_whole_repository_is_sized_again_on_reuse(): void
    {
        $this->fakeHubModel();
        $region = $this->region();
        // added before the size came from the loaded weights: every format of the repository
        $model = $this->hubModel(['revision_sha' => str_repeat('c', 40), 'size_bytes' => 4 * 90_000_000, 'metadata' => ['tags' => ['text-embeddings-inference'], 'files' => 12]]);

        $this->postJson('/api/model-services', ['hub_id' => 'sentence-transformers/all-MiniLM-L6-v2', 'knative_region_id' => $region->id, 'engine' => 'transformers'])
            ->assertCreated()
            ->assertJsonPath('model.size_bytes', 90_000_000)
            ->assertJsonPath('memory_mb', 1024);

        $this->assertSame(1, AiModel::count());
        $this->assertSame(['tags' => ['text-embeddings-inference'], 'files' => 12, 'weights_format' => 'safetensors'], $model->fresh()->metadata);

        // sized once: the Hub is not asked again
        $hubCalls = fn () => Http::recorded(fn ($request) => str_starts_with($request->url(), 'https://huggingface.co/'))->count();
        $asked = $hubCalls();
        $this->postJson('/api/model-services', ['hub_id' => 'sentence-transformers/all-MiniLM-L6-v2', 'knative_region_id' => $region->id, 'name' => 'again'])->assertCreated();
        $this->assertSame($asked, $hubCalls());
    }

    public function test_services_that_cannot_run_are_refused(): void
    {
        $region = $this->region();
        $uploaded = AiModel::create(['name' => 'local', 'source' => 'upload', 'task' => 'text-classification']);
        $llm = $this->hubModel(['name' => 'llm', 'hub_id' => 'acme/llm', 'task' => 'text-generation', 'library' => 'transformers']);

        $this->postJson('/api/model-services', ['ai_model_id' => $uploaded->id, 'knative_region_id' => $region->id, 'mode' => 'online'])
            ->assertStatus(422)->assertJsonValidationErrors('mode');

        $this->postJson('/api/model-services', ['ai_model_id' => $llm->id, 'knative_region_id' => $region->id, 'engine' => 'tei'])
            ->assertStatus(422)->assertJsonValidationErrors('engine');

        $this->postJson('/api/model-services', ['ai_model_id' => $llm->id, 'knative_region_id' => $region->id, 'engine' => 'vllm', 'device' => 'cpu'])
            ->assertStatus(422)->assertJsonValidationErrors('device');

        $this->postJson('/api/model-services', ['ai_model_id' => $llm->id, 'knative_region_id' => $region->id, 'engine' => 'custom'])
            ->assertStatus(422)->assertJsonValidationErrors('image');

        // GPU checks of the region: product unknown
        $this->postJson('/api/model-services', [
            'ai_model_id' => $llm->id, 'knative_region_id' => $region->id, 'engine' => 'vllm', 'device' => 'gpu', 'gpu' => ['count' => 1, 'type' => 'NVIDIA-H100'],
        ])->assertStatus(422)->assertJsonValidationErrors('gpu');

        Queue::assertNothingPushed();
        $this->assertSame(0, AiModelService::count());
    }

    public function test_a_gpu_text_generation_service_gets_vllm_and_a_gpu(): void
    {
        $region = $this->region();
        $llm = $this->hubModel(['name' => 'qwen', 'hub_id' => 'Qwen/Qwen2.5-0.5B-Instruct', 'task' => 'text-generation', 'library' => 'transformers', 'size_bytes' => 1_000_000_000]);

        $this->postJson('/api/model-services', ['ai_model_id' => $llm->id, 'knative_region_id' => $region->id, 'device' => 'gpu', 'deploy' => false])
            ->assertCreated()
            ->assertJsonPath('engine', 'vllm')
            ->assertJsonPath('gpu.count', 1)
            ->assertJsonPath('status', AiModelService::STATUS_STOPPED)
            ->assertJsonPath('playground.kind', 'chat')
            ->assertJsonPath('cold_start.suggest_min_scale', true);

        Queue::assertNotPushed(DeployModelServiceJob::class);
        // not deployed yet, but its engine image is pulled onto the GPU nodes already
        Queue::assertPushed(PrepullModelImagesJob::class, fn (PrepullModelImagesJob $job) => $job->regionId === $region->id
            && $job->images === ['docker.io/vllm/vllm-openai:v0.6.6', config('ai.images.busybox')]
            && $job->placement['tolerations'] === GpuScheduling::TOLERATIONS
            && $job->placement['affinity']['nodeAffinity']['requiredDuringSchedulingIgnoredDuringExecution']['nodeSelectorTerms'][0]['matchExpressions'][0]['key'] === 'nvidia.com/gpu.count');
    }

    public function test_update_redeploys_a_running_service_and_stop_and_delete(): void
    {
        $this->fakeCluster();
        $region = $this->region();
        $service = $this->modelService($this->hubModel(), $region);

        $this->patchJson("/api/model-services/{$service->id}", ['memory_mb' => 2048, 'min_scale' => 1])
            ->assertOk()
            ->assertJsonPath('memory_mb', 2048)
            ->assertJsonPath('status', AiModelService::STATUS_DEPLOYING);
        Queue::assertPushed(DeployModelServiceJob::class);

        $this->postJson("/api/model-services/{$service->id}/stop")->assertStatus(202)->assertJsonPath('status', AiModelService::STATUS_STOPPED);
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', "/services/{$service->service_name}"), 'Knative Service removed');

        $project = Project::create(['name' => 'consumer', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        AiModelBinding::create(['project_id' => $project->id, 'ai_model_service_id' => $service->id, 'alias' => 'EMBED']);

        $this->deleteJson("/api/model-services/{$service->id}")->assertStatus(409);
        AiModelBinding::query()->delete();
        $this->deleteJson("/api/model-services/{$service->id}")->assertNoContent();
        $this->assertNull(AiModelService::find($service->id));
    }

    public function test_the_playground_calls_allowed_paths_through_the_activator(): void
    {
        $region = $this->region();
        $service = $this->modelService($this->hubModel(), $region);
        $this->fakeCluster([
            "GET /apis/serving.knative.dev/v1/namespaces/fn/services/{$service->service_name}" => ['status' => ['latestReadyRevisionName' => "{$service->service_name}-00001"]],
            'POST /api/v1/namespaces/knative-serving/services/http:activator-service:80/proxy/v1/embeddings' => ['object' => 'list', 'data' => [['embedding' => [0.1, 0.2]]]],
        ]);

        $this->postJson("/api/model-services/{$service->id}/invoke", ['path' => '/v1/embeddings', 'body' => ['input' => 'hello']])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('body.data.0.embedding', [0.1, 0.2]);

        $call = $this->k8sCallsTo('POST', 'activator-service')[0];
        $this->assertSame(['input' => 'hello'], $call['body']);
        $this->assertSame([$service->service_name.'-00001'], $call['headers']['Knative-Serving-Revision']);
        $this->assertSame(['fn'], $call['headers']['Knative-Serving-Namespace']);

        $this->postJson("/api/model-services/{$service->id}/invoke", ['path' => '/admin/shutdown'])->assertStatus(422);
        $this->postJson("/api/model-services/{$service->id}/invoke", ['path' => '/v1/../secret'])->assertStatus(422);
    }

    public function test_demo_regions_answer_the_playground(): void
    {
        Setting::set('gpu_demo_enabled', 'true');
        $demo = $this->gpuRegion(null, ['is_demo' => true]);
        $service = $this->modelService($this->hubModel(), $demo);

        $this->postJson("/api/model-services/{$service->id}/invoke", ['path' => '/v1/embeddings', 'body' => ['input' => 'x']])
            ->assertOk()
            ->assertJsonPath('body.object', 'list');

        app(ModelServiceDeployer::class)->deploy($service->fresh());
        $this->assertSame(AiModelService::STATUS_READY, $service->fresh()->status, 'demo services are ready at once');
    }

    public function test_the_library_adds_uploaded_and_imported_models(): void
    {
        $region = $this->region();

        $this->postJson('/api/models', ['source' => 'upload', 'name' => 'my-classifier', 'task' => 'text-classification'])
            ->assertCreated()->assertJsonPath('source', 'upload')->assertJsonPath('revision', 'local');

        $this->postJson('/api/models', ['source' => 'pvc', 'name' => 'nfs-bert', 'task' => 'fill-mask', 'path' => '../../etc', 'knative_region_id' => $region->id])
            ->assertStatus(422)->assertJsonValidationErrors('path');

        $this->postJson('/api/models', ['source' => 'pvc', 'name' => 'nfs-bert', 'task' => 'fill-mask', 'path' => 'bert/base', 'knative_region_id' => $region->id])
            ->assertCreated()->assertJsonPath('source_ref', 'bert/base');
        Queue::assertPushed(StartModelSnapshotJob::class);
        $this->assertSame('snapshots/default/nfs-bert/local', AiModelSnapshot::firstOrFail()->path);

        $this->postJson('/api/models', ['source' => 'oci', 'name' => 'oci-bert', 'task' => 'fill-mask', 'reference' => 'registry.example.com/models/bert:v1', 'knative_region_id' => $region->id])
            ->assertCreated();
        $this->postJson('/api/models', ['source' => 'oci', 'name' => 'bad-ref', 'task' => 'fill-mask', 'reference' => 'not a reference', 'knative_region_id' => $region->id])
            ->assertStatus(422);

        $listed = $this->getJson('/api/models')->assertOk()->json();
        $this->assertCount(3, $listed['data']);
        $this->assertTrue($listed['abilities']['deploy']);
    }

    public function test_models_in_use_are_not_deleted_and_snapshots_are_mirrored(): void
    {
        $region = $this->region();
        $model = $this->hubModel();
        $this->modelService($model, $region);

        $this->deleteJson("/api/models/{$model->id}")->assertStatus(409);

        $this->postJson("/api/models/{$model->id}/snapshots", ['knative_region_id' => $region->id])
            ->assertStatus(202)
            ->assertJsonPath('status', AiModelSnapshot::STATUS_PENDING)
            ->assertJsonPath('revision', str_repeat('a', 40));
        Queue::assertPushed(StartModelSnapshotJob::class);

        // an uploaded model has no mirror
        $uploaded = AiModel::create(['name' => 'local', 'source' => 'upload', 'task' => 'fill-mask']);
        $this->postJson("/api/models/{$uploaded->id}/snapshots", ['knative_region_id' => $region->id])->assertStatus(422);
    }

    public function test_engines_catalog_and_gpu_quota_endpoints(): void
    {
        $region = $this->region();

        $this->getJson('/api/models/engines')->assertOk()->assertJsonPath('engines.vllm.devices', ['gpu']);
        $this->getJson("/api/models/gpu-quota?knative_region_id={$region->id}")->assertOk()
            ->assertJsonPath('quota', null)
            ->assertJsonPath('used', 0)
            ->assertJsonPath('remaining', null);
    }
}
