<?php

namespace Tests\Feature;

use App\Jobs\UpgradeKnativeServingJob;
use App\Models\KnativeRegion;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\KnativeInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Upgrade Serving (console Serving tab, POST /knative-regions/{id}/upgrade-serving): Knative
 * Serving moves to the next minor version — the operator Helm release to that minor's chart
 * when it is older, the version's Serving CRDs, then spec.version of the KnativeServing, until
 * the operator reports it ready. The button answered "already installed" before.
 */
class KnativeServingUpgradeTest extends TestCase
{
    use ActsWithRoles, BuildsAiFixtures, RefreshDatabase;

    private const CR = '/apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving';

    /** @var list<string> helm commands: "install <release> <version>" */
    private array $helmCalls = [];

    private string $operatorChart = 'knative-operator-v1.20.2';

    private string $crVersion = '1.20.0';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(HelmService::class, new class(fn (string $call) => $this->helmCalls[] = $call, fn () => $this->operatorChart) extends HelmService
        {
            public function __construct(private \Closure $record, private \Closure $chart) {}

            public function install(string $release, string $chart, string $namespace, array $setValues, string $kubeconfigYaml, ?string $version = null, array $valuesArray = [], array $setJsonValues = [], bool $wait = true): HelmResult
            {
                ($this->record)("install {$release} {$version}");

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

            public function getRepoChartEntries(string $repoName, string $chartName): array
            {
                return [['version' => 'v1.21.4', 'appVersion' => '1.21.4'], ['version' => 'v1.20.2', 'appVersion' => '1.20.2']];
            }

            public function deployedChartVersion(string $release, string $namespace, string $kubeconfigYaml, string $chartName): ?string
            {
                return self::chartVersionFromList(json_encode([['name' => $release, 'status' => 'deployed', 'chart' => ($this->chart)()]]), $release, $chartName);
            }
        });
    }

    private function cluster(string $k8sMinor = '33'): void
    {
        $crd = "apiVersion: apiextensions.k8s.io/v1\nkind: CustomResourceDefinition\nmetadata:\n  name: services.serving.knative.dev\n";
        $this->fakeCluster([
            'GET /version' => ['major' => '1', 'minor' => $k8sMinor],
            'GET /apis/apiextensions.k8s.io/v1/customresourcedefinitions/knativeservings.operator.knative.dev' => ['metadata' => ['name' => 'knativeservings.operator.knative.dev']],
            'GET /knative/serving/releases/download/knative-v1.21.0/serving-crds.yaml' => fn () => Http::response($crd),
            'GET '.self::CR => fn () => ['spec' => ['version' => $this->crVersion], 'status' => ['version' => $this->crVersion, 'conditions' => [['type' => 'Ready', 'status' => 'True']]]],
            'PATCH '.self::CR => function ($request) {
                $this->crVersion = json_decode($request->body(), true)['spec']['version'].'.0';

                return ['spec' => ['version' => $this->crVersion]];
            },
        ]);
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_operator_status' => 'ready', 'knative_serving_status' => 'ready', 'knative_serving_version' => '1.20.0',
        ], $attributes));
    }

    public function test_the_upgrade_moves_the_operator_the_crds_and_serving_to_the_next_minor(): void
    {
        $this->cluster();
        $region = $this->region();

        app(KnativeInstallService::class)->upgradeServing($region, '1.21');

        $this->assertSame(['install knative-operator 1.21.4'], $this->helmCalls);
        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/customresourcedefinitions/services.serving.knative.dev'), 'the Serving CRDs of 1.21');
        $this->assertSame(['spec' => ['version' => '1.21']], $this->k8sCallsTo('PATCH', self::CR)[0]['body']);
        $region->refresh();
        $this->assertSame('ready', $region->knative_serving_status);
        $this->assertSame('1.21.0', $region->knative_serving_version);
    }

    public function test_an_operator_new_enough_is_not_upgraded(): void
    {
        $this->operatorChart = 'knative-operator-v1.21.4';
        $this->cluster();

        app(KnativeInstallService::class)->upgradeServing($this->region(), '1.21');

        $this->assertSame([], $this->helmCalls);
        $this->assertSame('1.21.0', KnativeRegion::first()->knative_serving_version);
    }

    public function test_the_api_takes_only_the_next_supported_minor_of_a_ready_serving(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        Bus::fake([UpgradeKnativeServingJob::class]);

        $this->cluster('32');
        $region = $this->region();
        $this->postJson("/api/knative-regions/{$region->id}/upgrade-serving", ['version' => '1.21'])
            ->assertStatus(422)->assertJsonValidationErrors('version');   // needs Kubernetes 1.33
        $this->postJson("/api/knative-regions/{$region->id}/upgrade-serving", ['version' => '1.22'])
            ->assertStatus(422)->assertJsonPath('message', 'Knative upgrades one minor version at a time: from 1.20 to 1.21.');
        $this->postJson("/api/knative-regions/{$region->id}/upgrade-serving", ['version' => '1.20'])->assertStatus(422);

        $region->update(['knative_serving_status' => 'not_installed']);
        $this->postJson("/api/knative-regions/{$region->id}/upgrade-serving", ['version' => '1.21'])->assertStatus(422);
        Bus::assertNotDispatched(UpgradeKnativeServingJob::class);
    }

    public function test_the_api_queues_the_upgrade(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        Bus::fake([UpgradeKnativeServingJob::class]);
        $this->cluster('33');
        $region = $this->region();

        $this->postJson("/api/knative-regions/{$region->id}/upgrade-serving", ['version' => '1.21'])
            ->assertOk()->assertJsonPath('region.knative_serving_status', 'installing');

        Bus::assertDispatched(UpgradeKnativeServingJob::class, fn ($job) => $job->version === '1.21' && $job->region->is($region));
        $this->postJson("/api/knative-regions/{$region->id}/upgrade-serving", ['version' => '1.21'])->assertStatus(409);
    }
}
