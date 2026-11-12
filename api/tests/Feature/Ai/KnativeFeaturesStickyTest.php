<?php

namespace Tests\Feature\Ai;

use App\Models\AiModelService;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\Ai\Gpu\GpuSupportManifests;
use App\Services\InternalNetwork\InternalNetworkService;
use App\Services\KnativeInstallService;
use App\Services\KubernetesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * The pod spec features of model services (the model volume, a read-write PVC) are part of every
 * region's KnativeServing config: on from the install, kept by every region:reconcile
 * (RegionReconcileCommandTest), enabled again when Knative refuses a write for one of them — CI
 * run 43170135752: Knative's webhook refused the model service's Knative Service for 42 s after
 * its first deploy turned them on, and a metadata patch of it a minute and a half later
 * ("Persistent volume claim support is disabled").
 */
class KnativeFeaturesStickyTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    private const CR = '/apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving';

    private const CONFIG_FEATURES = '/api/v1/namespaces/knative-serving/configmaps/config-features';

    /** Knative's webhook refusal of the model volume while the features are off in its view */
    private const PVC_REFUSAL = 'admission webhook "validation.webhook.serving.knative.dev" denied the request: validation failed: '
        .'Persistent volume claim support is disabled, but found persistent volume claim runr-models: '
        ."\nPersistent volume write support is disabled, but found persistent volume claim runr-models that is not read-only: "
        ."\nmust not set the field(s): spec.template.spec.volumes[0].persistentVolumeClaim";

    public function test_a_new_region_has_the_model_volume_features_from_its_install(): void
    {
        $this->fakeCluster();
        $service = (new \ReflectionClass(KnativeInstallService::class))->newInstanceWithoutConstructor();
        $apply = new \ReflectionMethod($service, 'applyKnativeServingCR');
        $k8s = new KubernetesClient($this->fakeKubeconfig());

        $apply->invoke($service, $k8s, [], 'knative-serving', '1.20');
        $apply->invoke($service, $k8s, ['enable_podspec_features' => true], 'knative-serving', '1.20');

        [$plain, $workers] = $this->k8sCallsTo('PATCH', self::CR);
        $this->assertSame(KnativeInstallService::baseKnativeFeatures(), $plain['body']['spec']['config']['features']);
        $this->assertSame('enabled', $plain['body']['spec']['config']['features']['kubernetes.podspec-persistent-volume-claim']);
        $this->assertSame('enabled', $plain['body']['spec']['config']['features']['kubernetes.podspec-persistent-volume-write']);
        $this->assertSame('enabled', $workers['body']['spec']['config']['features']['kubernetes.podspec-tolerations']);
        $this->assertSame('enabled', $workers['body']['spec']['config']['features']['kubernetes.podspec-persistent-volume-claim']);
    }

    /** Knative validates the whole Knative Service on a metadata patch too (the callers' write) */
    public function test_a_feature_refusal_of_the_callers_write_enables_the_features_again(): void
    {
        Queue::fake();
        $region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig(), 'knative_serving_namespace' => 'knative-serving']);
        $service = $this->modelService($this->hubModel(), $region, ['service_name' => 'm-embed']);
        $this->fakeCluster([
            'GET /apis/serving.knative.dev/v1/namespaces/fn/services/m-embed' => ['metadata' => ['name' => 'm-embed', 'labels' => [AiModelService::LABEL => $service->id]]],
            'PATCH /apis/serving.knative.dev/v1/namespaces/fn/services/m-embed' => [['kind' => 'Status', 'code' => 400, 'message' => self::PVC_REFUSAL], 400],
            'GET '.self::CR => ['spec' => ['config' => ['features' => ['kubernetes.podspec-init-containers' => 'enabled']]]],
            'GET '.self::CONFIG_FEATURES => ['data' => GpuSupportManifests::knativeFeatures()],
        ]);

        $this->assertFalse(app(InternalNetworkService::class)->writeModelCallers($service), 'tried again by its job');

        $patches = $this->k8sCallsTo('PATCH', self::CR);
        $this->assertCount(1, $patches, 'the features enabled again');
        $this->assertSame(GpuSupportManifests::knativeFeatures(), $patches[0]['body']['spec']['config']['features']);
    }

    /** A CR that was not read is no install without the operator: config-features is the operator's */
    public function test_a_cr_that_was_not_read_leaves_config_features_to_the_operator(): void
    {
        $region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig(), 'knative_serving_namespace' => 'knative-serving']);
        $this->fakeCluster([
            'GET '.self::CR => [['kind' => 'Status', 'code' => 503, 'message' => 'the server is currently unable to handle the request'], 503],
            'GET '.self::CONFIG_FEATURES => ['data' => ['kubernetes.podspec-init-containers' => 'enabled']],
        ]);

        app(GpuInstallService::class)->enableKnativeFeatures(new KubernetesClient($region->kubeconfig), $region);

        $this->assertSame([], array_merge($this->k8sCallsTo('PATCH', 'config-features'), $this->k8sCallsTo('PUT', 'config-features'), $this->k8sCallsTo('POST', 'configmaps')),
            'a direct patch the operator would write over from the CR');
    }

    public function test_without_the_operator_config_features_gets_them(): void
    {
        $region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig(), 'knative_serving_namespace' => 'knative-serving']);
        $this->fakeCluster([
            'GET '.self::CONFIG_FEATURES => function (Request $request) {
                return ['data' => ['kubernetes.podspec-init-containers' => 'enabled']];
            },
        ]);

        app(GpuInstallService::class)->enableKnativeFeatures(new KubernetesClient($region->kubeconfig), $region);

        $this->assertCount(1, $this->k8sCallsTo('PATCH', 'config-features'), 'the CR is not there (404): the ConfigMap is RunR\'s to write');
        Http::assertSentCount(3);
    }
}
