<?php

namespace Tests\Feature\Mcp\Tools;

use App\Jobs\Ai\DeployModelServiceJob;
use App\Models\AiModelBinding;
use App\Models\AiModelService;
use App\Services\Ai\Hub\HubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * models_search, model_services_list, model_service_deploy, model_invoke, model_bind — the model
 * routes with the Hub client faked and deploy jobs queued (not run).
 */
class ModelToolsTest extends TestCase
{
    use BuildsAiFixtures, McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        Queue::fake();
    }

    public function test_the_hub_is_searched(): void
    {
        $this->mock(HubClient::class, fn ($m) => $m->shouldReceive('search')
            ->withArgs(fn ($search, $task) => $search === 'minilm' && $task === 'feature-extraction')
            ->andReturn(['data' => [['id' => 'sentence-transformers/all-MiniLM-L6-v2', 'task' => 'feature-extraction', 'downloads' => 10]], 'next_cursor' => 'c2']));

        $result = $this->structured($this->callTool('models_search', ['search' => 'minilm', 'task' => 'feature-extraction']));

        $this->assertSame('sentence-transformers/all-MiniLM-L6-v2', $result['models'][0]['id']);
        $this->assertSame('c2', $result['next_cursor']);
    }

    public function test_model_services_are_listed_without_env_values(): void
    {
        $this->modelService($this->hubModel(), $this->region, ['name' => 'minilm', 'env_vars' => ['HF_HUB_TOKEN' => 'hf-do-not-leak']]);

        $result = $this->callTool('model_services_list');
        $data = $this->structured($result);

        $this->assertSame('minilm', $data['services'][0]['name']);
        $this->assertSame(['HF_HUB_TOKEN'], $data['services'][0]['env_names']);
        $this->assertStringNotContainsString('hf-do-not-leak', json_encode($result));
    }

    public function test_a_model_service_is_deployed_and_waited_for(): void
    {
        $model = $this->hubModel();
        $this->onPoll = fn () => AiModelService::query()->where('status', AiModelService::STATUS_DEPLOYING)->update(['status' => AiModelService::STATUS_READY]);

        $result = $this->structured($this->callTool('model_service_deploy', ['model' => $model->id, 'region' => 'op', 'name' => 'minilm']));

        $this->assertSame('ready', $result['service']['status']);
        $this->assertSame('minilm', $result['service']['name']);
        Queue::assertPushed(DeployModelServiceJob::class);
    }

    public function test_model_service_deploy_validation(): void
    {
        $this->mcpToolError($this->callTool('model_service_deploy', ['region' => 'op']));
        $this->assertSchemaRejects('model_service_deploy', ['hub_id' => 'a/b', 'name' => 'Not Valid'], '/name');
        $this->assertSchemaRejects('model_service_deploy', ['hub_id' => 'a/b', 'device' => 'tpu'], '/device');

        // The route refuses an engine that cannot serve the task
        $llm = $this->hubModel(['name' => 'llm', 'hub_id' => 'acme/llm', 'task' => 'text-generation', 'library' => 'transformers']);
        $error = $this->mcpToolError($this->callTool('model_service_deploy', ['model' => $llm->id, 'region' => 'op', 'engine' => 'tei']));
        $this->assertSame(422, $error['status']);
        $this->assertSame(0, AiModelService::count());
    }

    public function test_a_model_service_is_invoked_through_the_playground(): void
    {
        $service = $this->modelService($this->hubModel(), $this->region, ['name' => 'minilm']);

        $result = $this->structured($this->callTool('model_invoke', ['service' => 'minilm', 'path' => '/v1/embeddings', 'body' => ['input' => 'hello']]));

        $this->assertSame(200, $result['status']);

        $error = $this->mcpToolError($this->callTool('model_invoke', ['service' => $service->id, 'path' => '/admin/shutdown']));
        $this->assertSame(422, $error['status']);
    }

    public function test_a_project_is_bound_to_a_model_service(): void
    {
        $service = $this->modelService($this->hubModel(), $this->region, ['name' => 'minilm', 'service_name' => 'm-minilm', 'engine' => 'tei']);
        $project = $this->containerProject('search');

        $result = $this->structured($this->callTool('model_bind', ['project' => 'search', 'service' => 'minilm', 'alias' => 'EMBED']));

        $this->assertSame('EMBED', $result['binding']['alias']);
        $this->assertTrue($result['binding']['requires_deploy']);
        $this->assertSame(1, AiModelBinding::where('project_id', $project->id)->count());
        $this->assertSchemaRejects('model_bind', ['project' => 'search', 'service' => 'minilm', 'alias' => 'lower'], '/alias');
    }

    public function test_model_permissions(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->structured($this->callTool('model_services_list', [], $viewer));
        $this->assertToolHidden('model_service_deploy', ['*'], ['hub_id' => 'a/b'], $viewer);
        $this->assertToolHidden('model_bind', ['models.deploy'], ['project' => 'x', 'service' => 'y']);
        $this->assertToolHidden('models_search', ['projects.view']);
        $this->assertToolHidden('model_invoke', ['projects.view'], ['service' => 'x', 'path' => '/v1/embeddings']);
    }
}
