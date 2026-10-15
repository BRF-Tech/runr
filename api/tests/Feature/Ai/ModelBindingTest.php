<?php

namespace Tests\Feature\Ai;

use App\Models\AiModelBinding;
use App\Models\AiModelSnapshot;
use App\Models\User;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAiFixtures;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Binding model services to projects: the API (same region only, unique aliases, mounted
 * weights need an offline snapshot) and what the project's containers get at deploy —
 * RUNR_MODEL_<ALIAS>_URL / _MODEL / _API / _TASK / _OPENAI_BASE, RUNR_MODELS and, with
 * mount_weights, the snapshot read-only at /models/<alias> (RUNR_MODEL_<ALIAS>_PATH).
 */
class ModelBindingTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase, RegistersRuntimes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        $this->actingAs(User::factory()->create(), 'sanctum');
    }

    private function snapshot($model, $region): AiModelSnapshot
    {
        return AiModelSnapshot::create([
            'ai_model_id' => $model->id, 'knative_region_id' => $region->id, 'source' => 'hub', 'revision' => str_repeat('a', 40),
            'path' => 'snapshots/default/'.$model->name.'/'.str_repeat('a', 40), 'status' => AiModelSnapshot::STATUS_READY, 'completed_at' => now(),
        ]);
    }

    public function test_binding_api_and_env_preview(): void
    {
        [$project] = $this->runtimeProject('python', 'http', ['main.py' => 'def handler(e, c): return 1']);
        $region = $project->knativeRegion;
        $service = $this->modelService($this->hubModel(), $region, ['name' => 'minilm', 'service_name' => 'm-minilm', 'engine' => 'tei']);

        $response = $this->postJson("/api/projects/{$project->id}/models", ['ai_model_service_id' => $service->id, 'alias' => 'EMBED'])
            ->assertCreated()
            ->assertJsonPath('alias', 'EMBED')
            ->assertJsonPath('applies_on_next_deploy', true)
            ->assertJsonPath('env.RUNR_MODEL_EMBED_URL', 'http://m-minilm.fn.svc.cluster.local')
            ->assertJsonPath('env.RUNR_MODEL_EMBED_OPENAI_BASE', 'http://m-minilm.fn.svc.cluster.local/v1')
            ->assertJsonPath('env.RUNR_MODEL_EMBED_API', 'tei')
            ->assertJsonPath('env.RUNR_MODEL_EMBED_TASK', 'feature-extraction');

        $this->assertArrayNotHasKey('RUNR_MODEL_EMBED_PATH', $response->json('env'));

        // the same service twice, an alias twice, a bad alias
        $this->postJson("/api/projects/{$project->id}/models", ['ai_model_service_id' => $service->id, 'alias' => 'OTHER'])->assertStatus(422);
        $other = $this->modelService($this->hubModel(['name' => 'other']), $region);
        $this->postJson("/api/projects/{$project->id}/models", ['ai_model_service_id' => $other->id, 'alias' => 'EMBED'])->assertStatus(422);
        $this->postJson("/api/projects/{$project->id}/models", ['ai_model_service_id' => $other->id, 'alias' => 'lower-case'])->assertStatus(422);

        // default alias from the service name
        $this->postJson("/api/projects/{$project->id}/models", ['ai_model_service_id' => $other->id])
            ->assertCreated()->assertJsonPath('alias', AiModelBinding::aliasFor($other->name));

        $list = $this->getJson("/api/projects/{$project->id}/models")->assertOk()->json();
        $this->assertCount(2, $list['data']);
        $this->assertCount(2, $list['available']);

        $binding = AiModelBinding::where('alias', 'EMBED')->firstOrFail();
        $this->patchJson("/api/projects/{$project->id}/models/{$binding->id}", ['alias' => 'VECTORS'])->assertOk()->assertJsonPath('alias', 'VECTORS');
        $this->deleteJson("/api/projects/{$project->id}/models/{$binding->id}")->assertNoContent();
    }

    public function test_services_of_other_regions_and_weights_without_snapshot_are_refused(): void
    {
        [$project] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $elsewhere = $this->gpuRegion();
        $remote = $this->modelService($this->hubModel(), $elsewhere);

        $this->postJson("/api/projects/{$project->id}/models", ['ai_model_service_id' => $remote->id])
            ->assertStatus(422)->assertJsonValidationErrors('ai_model_service_id');

        $local = $this->modelService($this->hubModel(['name' => 'local']), $project->knativeRegion);
        $this->postJson("/api/projects/{$project->id}/models", ['ai_model_service_id' => $local->id, 'mount_weights' => true])
            ->assertStatus(422)->assertJsonValidationErrors('mount_weights');

        $this->snapshot($local->model, $project->knativeRegion);
        $this->postJson("/api/projects/{$project->id}/models", ['ai_model_service_id' => $local->id, 'alias' => 'LOCAL', 'mount_weights' => true])
            ->assertCreated()->assertJsonPath('env.RUNR_MODEL_LOCAL_PATH', '/models/local');
    }

    public function test_deploy_injects_the_binding_env_and_mounts_the_weights(): void
    {
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'def handler(e, c): return 1']);
        $region = $project->knativeRegion;

        $embed = $this->modelService($this->hubModel(), $region, ['name' => 'minilm', 'service_name' => 'm-minilm', 'engine' => 'tei']);
        $chat = $this->modelService(
            $this->hubModel(['name' => 'qwen', 'hub_id' => 'Qwen/Qwen2.5-0.5B-Instruct', 'task' => 'text-generation', 'library' => 'transformers']),
            $region,
            ['name' => 'qwen', 'service_name' => 'm-qwen', 'engine' => 'vllm', 'device' => 'gpu', 'gpu' => ['count' => 1], 'served_name' => 'qwen'],
        );
        $snapshot = $this->snapshot($embed->model, $region);

        AiModelBinding::create(['project_id' => $project->id, 'ai_model_service_id' => $embed->id, 'alias' => 'EMBED', 'mount_weights' => true]);
        AiModelBinding::create(['project_id' => $project->id, 'ai_model_service_id' => $chat->id, 'alias' => 'CHAT']);

        $ksvc = collect(app(KnativeDeployService::class)->buildManifests($project->fresh(['xaasConfig', 'knativeRegion']), $version))->firstWhere('kind', 'Service');
        $spec = $ksvc['spec']['template']['spec'];
        $container = $spec['containers'][0];
        $env = array_column($container['env'], 'value', 'name');

        $this->assertSame('http://m-minilm.fn.svc.cluster.local', $env['RUNR_MODEL_EMBED_URL']);
        $this->assertSame('/models/embed', $env['RUNR_MODEL_EMBED_PATH']);
        $this->assertSame('http://m-qwen.fn.svc.cluster.local', $env['RUNR_MODEL_CHAT_URL']);
        $this->assertSame('http://m-qwen.fn.svc.cluster.local/v1', $env['RUNR_MODEL_CHAT_OPENAI_BASE']);
        $this->assertSame('qwen', $env['RUNR_MODEL_CHAT_MODEL']);
        $this->assertSame('openai', $env['RUNR_MODEL_CHAT_API']);
        $this->assertSame('CHAT,EMBED', $env['RUNR_MODELS']);
        $this->assertArrayNotHasKey('RUNR_MODEL_CHAT_PATH', $env);

        $this->assertContains(['name' => 'runr-models', 'mountPath' => '/models/embed', 'subPath' => $snapshot->path, 'readOnly' => true], $container['volumeMounts']);
        $this->assertContains(['name' => 'runr-models', 'persistentVolumeClaim' => ['claimName' => 'runr-models', 'readOnly' => true]], $spec['volumes']);
        $this->assertArrayNotHasKey('nvidia.com/gpu', $container['resources']['limits'], 'the function itself needs no GPU to call one');
    }

    public function test_user_env_vars_win_over_binding_env(): void
    {
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $project->xaasConfig->update(['env_vars' => ['RUNR_MODEL_EMBED_URL' => 'http://override.example.com']]);
        $service = $this->modelService($this->hubModel(), $project->knativeRegion, ['engine' => 'tei']);
        AiModelBinding::create(['project_id' => $project->id, 'ai_model_service_id' => $service->id, 'alias' => 'EMBED']);

        $ksvc = collect(app(KnativeDeployService::class)->buildManifests($project->fresh(['xaasConfig', 'knativeRegion']), $version))->firstWhere('kind', 'Service');
        $urls = array_values(array_filter($ksvc['spec']['template']['spec']['containers'][0]['env'], fn ($e) => $e['name'] === 'RUNR_MODEL_EMBED_URL'));

        $this->assertSame([['name' => 'RUNR_MODEL_EMBED_URL', 'value' => 'http://override.example.com']], $urls);
    }

    public function test_aliases_from_service_names(): void
    {
        $this->assertSame('MINILM_L6', AiModelBinding::aliasFor('minilm-l6'));
        $this->assertSame('M_7B', AiModelBinding::aliasFor('7b'));
        $this->assertSame(32, strlen(AiModelBinding::aliasFor(str_repeat('a', 50))));
    }
}
