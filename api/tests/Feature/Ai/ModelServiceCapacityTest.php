<?php

namespace Tests\Feature\Ai;

use App\Http\Requests\Project\StoreProjectRequest;
use App\Models\AiModelService;
use App\Models\KnativeRegion;
use App\Models\User;
use App\Rules\WithinResourceLimit;
use App\Services\Ai\Models\ModelServiceReservations;
use App\Services\Contracts\KnativeConstants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Model services count in the region no-overcommit capacity next to the projects: project
 * admission (WithinResourceLimit), model service admission, region usage (region resource) and
 * capacity alerts. Stopped services reserve nothing; a running service that does not grow is
 * always admitted.
 */
class ModelServiceCapacityTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->actingAs(User::factory()->create(), 'sanctum');
        // 4000m / 8192 MiB for workloads
        $this->region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig(), 'total_cpu_m' => 4000, 'total_memory_mb' => 8192]);
    }

    private function cpuCheck(string $cpu): array
    {
        return Validator::make(['cpu' => $cpu], [
            'cpu' => [new WithinResourceLimit('cpu', null, $this->region->id, 'http', 0, 'caas')],
        ])->errors()->toArray();
    }

    public function test_project_admission_counts_running_model_services(): void
    {
        // 3000m + queue-proxy reserved by a running model service
        $this->modelService($this->hubModel(), $this->region, ['cpu' => '3', 'memory_mb' => 2048, 'status' => AiModelService::STATUS_READY]);

        $this->assertArrayHasKey('cpu', $this->cpuCheck('1'), '1000m + 100m does not fit next to 3100m');
        $this->assertSame([], $this->cpuCheck('500m'));

        // a stopped service holds nothing
        AiModelService::query()->update(['status' => AiModelService::STATUS_STOPPED]);
        $this->assertSame([], $this->cpuCheck('2'));
    }

    public function test_model_service_admission_checks_the_region_capacity(): void
    {
        [$project] = $this->gpuCaasProject($this->region, null, ['cpu' => '2', 'memory_mb' => 1024]);
        $model = $this->hubModel(['task' => 'feature-extraction']);

        // 2000m + 100m used by the project; 2 CPUs more do not fit
        $this->postJson('/api/model-services', [
            'ai_model_id' => $model->id, 'knative_region_id' => $this->region->id, 'engine' => 'tei', 'device' => 'cpu', 'cpu' => '2',
        ])->assertStatus(422)->assertJsonValidationErrors('cpu');

        $created = $this->postJson('/api/model-services', [
            'ai_model_id' => $model->id, 'knative_region_id' => $this->region->id, 'engine' => 'tei', 'device' => 'cpu', 'cpu' => '1',
        ])->assertCreated()->json();

        // the running service may stay as it is, but not grow beyond the region
        $this->patchJson("/api/model-services/{$created['id']}", ['min_scale' => 0])->assertOk();
        $this->patchJson("/api/model-services/{$created['id']}", ['cpu' => '3'])->assertStatus(422)->assertJsonValidationErrors('cpu');

        // without deploying (deploy: false) nothing is reserved yet; deploying checks again
        $stopped = $this->postJson('/api/model-services', [
            'ai_model_id' => $model->id, 'knative_region_id' => $this->region->id, 'engine' => 'tei', 'device' => 'cpu', 'cpu' => '2', 'deploy' => false, 'name' => 'later',
        ])->assertCreated()->json();
        $this->postJson("/api/model-services/{$stopped['id']}/deploy")->assertStatus(422);
    }

    public function test_region_usage_and_reservations_include_model_services(): void
    {
        $service = $this->modelService($this->hubModel(), $this->region, ['cpu' => '1', 'memory_mb' => 1024, 'min_scale' => 2]);

        $reservation = ModelServiceReservations::forService($service, $this->region);
        $this->assertSame(2 * (1000 + KnativeConstants::QUEUE_PROXY_CPU_M), $reservation['cpu_m'], 'size × max(1, min_scale) + queue-proxy');
        $this->assertSame($reservation, ModelServiceReservations::sumIn($this->region->id));
        $this->assertSame($reservation, ModelServiceReservations::sumIn(null), 'every region');
        $this->assertSame(['cpu_m' => 0, 'memory_mb' => 0], ModelServiceReservations::sumIn($this->region->id, $service->id));

        $this->getJson("/api/knative-regions/{$this->region->id}")
            ->assertOk()
            ->assertJsonPath('data.used_cpu_m', $reservation['cpu_m'])
            ->assertJsonPath('data.used_memory_mb', $reservation['memory_mb']);
    }

    public function test_project_create_requests_see_the_services(): void
    {
        $this->modelService($this->hubModel(), $this->region, ['cpu' => '3500m', 'memory_mb' => 1024]);

        $request = StoreProjectRequest::create('/api/projects', 'POST', [
            'name' => 'too-big',
            'service_type' => 'caas',
            'knative_region_id' => $this->region->id,
            'config' => ['image' => 'nginx:1.27-alpine', 'cpu' => '500m'],
        ]);
        $request->setContainer($this->app);
        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertArrayHasKey('config.cpu', $validator->errors()->toArray());
    }
}
