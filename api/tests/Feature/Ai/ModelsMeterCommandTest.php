<?php

namespace Tests\Feature\Ai;

use App\Models\AiModelService;
use App\Models\AiModelServiceUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * models:meter (every minute): the running pods of the deploying and ready model services are
 * listed once per region and sampled into the services' day; demo regions, regions without a
 * cluster and stopped services are left out, a region whose pods can not be listed is skipped
 * (and logged) without stopping the others.
 */
class ModelsMeterCommandTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_running_model_services_are_metered_per_region(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $model = $this->hubModel();
        $region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()]);
        $down = $this->gpuRegion(null, ['kubeconfig' => str_replace('region.test', 'down.test', $this->fakeKubeconfig())]);
        $demo = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig(), 'is_demo' => true]);
        $noCluster = $this->gpuRegion();

        $ready = $this->modelService($model, $region, ['status' => AiModelService::STATUS_READY]);
        $deploying = $this->modelService($model, $region, ['status' => AiModelService::STATUS_DEPLOYING]);
        $stopped = $this->modelService($model, $region, ['status' => AiModelService::STATUS_STOPPED]);
        $unreachable = $this->modelService($model, $down);
        $this->modelService($model, $demo);
        $this->modelService($model, $noCluster);

        $pod = function (AiModelService $service, string $name) {
            $pod = $this->gpuPod($name, 'gpu-a', 1, [AiModelService::LABEL => $service->id]);
            $pod['status']['startTime'] = '2026-10-15T11:59:30Z';

            return $pod;
        };
        $this->fakeCluster([
            'GET /api/v1/namespaces/fn/pods' => function ($request) use ($pod, $ready, $deploying, $stopped) {
                if (str_contains($request->url(), 'down.test')) {
                    throw new ConnectionException('Connection timed out');
                }

                return ['items' => [$pod($ready, 'm-ready-1'), $pod($deploying, 'm-deploying-1'), $pod($stopped, 'm-stopped-1')]];
            },
        ]);
        Log::spy();

        $this->artisan('models:meter')->expectsOutput('Metered 2 model service(s).')->assertSuccessful();

        // one listing per region, of the model service pods
        $listings = $this->k8sCallsTo('GET', '/namespaces/fn/pods');
        $this->assertCount(2, $listings, 'the reachable region once, the unreachable one once');
        $this->assertSame('labelSelector='.urlencode('app.kubernetes.io/component=model-service'), $listings[0]['query']);

        // 30 s since each pod started, 1 GPU, 2 CPUs and 8 GiB requested (gpuPod)
        foreach ([$ready, $deploying] as $service) {
            $usage = AiModelServiceUsage::where('ai_model_service_id', $service->id)->sole();
            $this->assertSame(30_000, (int) $usage->replica_ms);
            $this->assertSame(30_000, (int) $usage->gpu_unit_ms);
            $this->assertSame(30_000 * 2000, (int) $usage->cpu_mc_ms);
            $this->assertSame(30_000 * 8192, (int) $usage->memory_mib_ms);
            $this->assertSame($region->id, $usage->knative_region_id);
        }
        $this->assertSame(2, AiModelServiceUsage::count(), 'not the stopped service, nor the demo, cluster-less or unreachable regions');
        $this->assertSame(0, AiModelServiceUsage::where('ai_model_service_id', $unreachable->id)->count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Model usage metering: pods could not be listed' && $context['region'] === $down->name)->once();

        // a minute later: the next sample adds the minute to the same day
        Carbon::setTestNow('2026-10-15 12:01:00');
        $this->artisan('models:meter')->assertSuccessful();
        $this->assertSame(90_000, (int) AiModelServiceUsage::where('ai_model_service_id', $ready->id)->sole()->replica_ms);
    }
}
