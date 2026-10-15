<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\Ai\Gpu\GpuSupportManifests;
use App\Services\KubernetesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * The first model service of a CPU region: its deploy enables the Knative PVC features through
 * the operator's KnativeServing CR and applied the Knative Service in the same breath, before the
 * operator had carried them into config-features — Knative's webhook refused it ("Persistent volume
 * claim support is disabled, but found persistent volume claim runr-models"). The deploy now waits
 * until config-features carries them; where they are in force already it does not wait.
 */
class KnativeFeaturesInForceTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    private const CR = 'GET /apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving';

    private const FEATURES = 'GET /api/v1/namespaces/knative-serving/configmaps/config-features';

    private function enable(bool $wait): void
    {
        $region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()]);
        app(GpuInstallService::class)->enableKnativeFeatures(new KubernetesClient($region->kubeconfig), $region, waitUntilEffective: $wait);
    }

    public function test_the_deploy_waits_until_config_features_carries_the_features(): void
    {
        Sleep::fake();
        $reads = 0;
        $this->fakeCluster([
            self::CR => ['metadata' => ['name' => 'knative-serving']],
            self::FEATURES => function () use (&$reads) {
                $reads++;

                return ['data' => $reads < 4 ? ['kubernetes.podspec-persistent-volume-claim' => 'disabled'] : GpuSupportManifests::knativeFeatures()];
            },
        ]);

        $this->enable(true);

        $this->assertSame(4, $reads, 'read once before the CR patch, then until the operator carried it over');
        $this->assertCount(1, $this->k8sCallsTo('PATCH', '/knativeservings/knative-serving'));
        Sleep::assertSleptTimes(3);
    }

    public function test_features_in_force_already_are_not_waited_for(): void
    {
        Sleep::fake();
        $this->fakeCluster([
            self::CR => ['metadata' => ['name' => 'knative-serving']],
            self::FEATURES => ['data' => GpuSupportManifests::knativeFeatures() + ['kubernetes.podspec-dryrun' => 'allowed']],
        ]);

        $this->enable(true);

        $this->assertCount(1, $this->k8sCallsTo('PATCH', '/knativeservings/knative-serving'), 'the CR keeps them');
        Sleep::assertNeverSlept();
    }

    public function test_callers_that_apply_no_knative_service_do_not_wait(): void
    {
        Sleep::fake();
        $this->fakeCluster([
            self::CR => ['metadata' => ['name' => 'knative-serving']],
            self::FEATURES => ['data' => []],
        ]);

        $this->enable(false);

        $this->assertCount(1, $this->k8sCallsTo('GET', '/configmaps/config-features'));
        Sleep::assertNeverSlept();
    }

    public function test_features_in_force_reads_the_configmap_data(): void
    {
        $features = ['kubernetes.podspec-persistent-volume-claim' => 'enabled'];

        $this->assertTrue(GpuInstallService::featuresInForce(['data' => $features + ['x' => 'y']], $features));
        $this->assertFalse(GpuInstallService::featuresInForce(['data' => ['kubernetes.podspec-persistent-volume-claim' => 'disabled']], $features));
        $this->assertFalse(GpuInstallService::featuresInForce(null, $features));
    }
}
