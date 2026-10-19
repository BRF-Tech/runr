<?php

namespace Tests\Feature;

use App\Jobs\InstallKnativeJob;
use App\Models\KnativeRegion;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\KnativeInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * The full Knative install (POST install-knative, `runr regions knative install REGION`) adopts
 * what already runs on the cluster, like the operator and Serving installs: installing the
 * operator again starts by removing the previous installation and the Serving CRDs — every
 * Knative Service on the cluster — and applying the KnativeServing again would replace another
 * region's settings. The operator install itself adopts a running operator, and its cleanup of a
 * broken install keeps the CRDs while Knative Services exist.
 */
class KnativeInstallAdoptionTest extends TestCase
{
    use ActsWithRoles, BuildsAiFixtures, RefreshDatabase;

    /** @var object{calls: list<string>} */
    private object $helm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helm = new class extends HelmService
        {
            /** @var list<string> */
            public array $calls = [];

            public function install(string $release, string $chart, string $namespace, array $setValues, string $kubeconfigYaml, ?string $version = null, array $valuesArray = [], array $setJsonValues = [], bool $wait = true): HelmResult
            {
                $this->calls[] = "install {$release}";

                return new HelmResult(false, '', 'not expected in this test', 1);
            }

            public function uninstall(string $release, string $namespace, string $kubeconfigYaml): HelmResult
            {
                $this->calls[] = "uninstall {$release}";

                return new HelmResult(true, '', '', 0);
            }

            public function repoAdd(string $name, string $url): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }

            public function repoUpdate(): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }
        };
        $this->app->instance(HelmService::class, $this->helm);
    }

    private function region(): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => 'eu-2', 'project_namespace' => 'eu-2', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
        ]);
    }

    /** A cluster whose operator runs (CRD + available Deployment) and, with $serving, a Ready KnativeServing. */
    private function clusterWithKnative(bool $serving = true, array $knativeServices = []): void
    {
        $this->fakeCluster([
            'GET /apis/apiextensions.k8s.io/v1/customresourcedefinitions/knativeservings.operator.knative.dev' => ['metadata' => ['name' => 'knativeservings.operator.knative.dev']],
            'GET /apis/apiextensions.k8s.io/v1/customresourcedefinitions/services.serving.knative.dev' => ['metadata' => ['name' => 'services.serving.knative.dev']],
            'GET /apis/apps/v1/namespaces/knative-operator/deployments' => ['items' => [
                ['metadata' => ['name' => 'knative-operator'], 'spec' => ['replicas' => 1], 'status' => ['availableReplicas' => 1]],
            ]],
            'GET /apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving' => $serving
                ? ['status' => ['version' => '1.20.0', 'conditions' => [['type' => 'Ready', 'status' => 'True']]]]
                : [['kind' => 'Status', 'code' => 404], 404],
            'GET /api/v1/namespaces/knative-serving/services/traefik' => ['status' => ['loadBalancer' => ['ingress' => [['ip' => '203.0.113.7']]]]],
            'GET /apis/serving.knative.dev/v1/services' => ['items' => $knativeServices],
        ]);
    }

    public function test_the_full_install_adopts_a_running_operator_and_serving(): void
    {
        $this->clusterWithKnative();
        $region = $this->region();

        app(KnativeInstallService::class)->install($region);

        $region->refresh();
        $this->assertSame('ready', $region->knative_operator_status);
        $this->assertSame('ready', $region->knative_serving_status);
        $this->assertSame('1.20.0', $region->knative_serving_version);
        $this->assertSame('203.0.113.7', $region->external_ip);
        $this->assertStringContainsString('Adopted', (string) $region->knative_serving_status_message);
        $this->assertSame([], $this->helm->calls, 'nothing installed, nothing uninstalled');
        $this->assertSame([], $this->k8sCallsTo('DELETE', '/customresourcedefinitions/'), 'no CRD removed');
        $this->assertSame([], $this->k8sCallsTo('PATCH', '/knativeservings/'), 'the KnativeServing is not applied again');
    }

    public function test_install_knative_through_the_api_adopts_too(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        $this->clusterWithKnative();
        $region = $this->region();

        $this->postJson("/api/knative-regions/{$region->id}/install-knative")->assertOk();

        $this->assertSame('ready', $region->fresh()->knative_status);
        $this->assertSame([], $this->k8sCallsTo('DELETE', '/customresourcedefinitions/'));
    }

    public function test_the_operator_install_adopts_a_running_operator(): void
    {
        $this->clusterWithKnative(serving: false);
        $region = $this->region();

        (new InstallKnativeJob($region, 'operator'))->handle(app(KnativeInstallService::class));

        $region->refresh();
        $this->assertSame('ready', $region->knative_operator_status);
        $this->assertSame('knative-operator', $region->knative_operator_namespace);
        $this->assertSame([], $this->helm->calls);
        $this->assertSame([], $this->k8sCallsTo('DELETE', '/customresourcedefinitions/'));
    }

    public function test_cleanup_before_an_install_keeps_the_crds_while_knative_services_exist(): void
    {
        $this->fakeCluster([
            'GET /apis/apiextensions.k8s.io/v1/customresourcedefinitions/services.serving.knative.dev' => ['metadata' => ['name' => 'services.serving.knative.dev']],
            'GET /apis/serving.knative.dev/v1/services' => ['items' => [['metadata' => ['name' => 'shop', 'namespace' => 'eu-1']]]],
        ]);
        $region = $this->region();

        try {
            app(KnativeInstallService::class)->installOperator($region, 'knative-operator');
            $this->fail('the scripted Helm install fails');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Helm install failed', $e->getMessage());
        }

        $this->assertSame(['uninstall knative-operator', 'install knative-operator'], $this->helm->calls);
        $this->assertSame([], $this->k8sCallsTo('DELETE', '/customresourcedefinitions/'), 'the CRDs of the running Knative Services stay');
    }

    public function test_cleanup_before_an_install_removes_the_crds_of_an_empty_cluster(): void
    {
        $this->fakeCluster([
            'GET /apis/apiextensions.k8s.io/v1/customresourcedefinitions/services.serving.knative.dev' => ['metadata' => ['name' => 'services.serving.knative.dev']],
            'GET /apis/serving.knative.dev/v1/services' => ['items' => []],
        ]);

        try {
            app(KnativeInstallService::class)->installOperator($this->region(), 'knative-operator');
        } catch (\RuntimeException) {
        }

        $this->assertNotEmpty($this->k8sCallsTo('DELETE', '/customresourcedefinitions/services.serving.knative.dev'));
    }
}
