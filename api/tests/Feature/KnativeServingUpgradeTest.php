<?php

namespace Tests\Feature;

use App\Jobs\InstallKnativeJob;
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

    /** The label of the Knative Operator's Deployment; null: no operator runs */
    private ?string $operatorVersion = null;

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
                return [
                    ['version' => 'v1.24.0', 'appVersion' => '1.24.0'], ['version' => 'v1.23.1', 'appVersion' => '1.23.1'],
                    ['version' => 'v1.22.3', 'appVersion' => '1.22.3'], ['version' => 'v1.21.4', 'appVersion' => '1.21.4'],
                    ['version' => 'v1.20.2', 'appVersion' => '1.20.2'], ['version' => 'v1.19.6', 'appVersion' => '1.19.6'],
                    ['version' => 'v1.18.3', 'appVersion' => '1.18.3'],
                ];
            }

            public function deployedChartVersion(string $release, string $namespace, string $kubeconfigYaml, string $chartName): ?string
            {
                return self::chartVersionFromList(json_encode([['name' => $release, 'status' => 'deployed', 'chart' => ($this->chart)()]]), $release, $chartName);
            }
        });
    }

    private function cluster(string $k8sMinor = '33'): void
    {
        $crd = "# Copyright The Knative Authors\n\napiVersion: apiextensions.k8s.io/v1\nkind: CustomResourceDefinition\nmetadata:\n  name: services.serving.knative.dev\n";
        $this->fakeCluster([
            'GET /version' => ['major' => '1', 'minor' => $k8sMinor],
            'GET /apis/apiextensions.k8s.io/v1/customresourcedefinitions/knativeservings.operator.knative.dev' => ['metadata' => ['name' => 'knativeservings.operator.knative.dev']],
            'GET /knative/serving/releases/download/knative-v1.21.0/serving-crds.yaml' => fn () => Http::response($crd),
            'GET /apis/apps/v1/namespaces/knative-operator/deployments/knative-operator' => fn () => $this->operatorVersion
                ? ['metadata' => ['labels' => ['app.kubernetes.io/version' => $this->operatorVersion]]]
                : Http::response(['kind' => 'Status', 'code' => 404], 404),
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

    /** A cluster on a newer Kubernetes supports more minors than it is offered for a new install. */
    public function test_a_region_behind_the_offered_minors_can_still_take_its_next_one(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        Bus::fake([UpgradeKnativeServingJob::class]);
        $this->operatorVersion = '1.18.3';
        $this->cluster('35');
        $region = $this->region(['knative_serving_version' => '1.18.0']);

        $this->getJson("/api/knative-regions/{$region->id}/compatible-versions")
            ->assertOk()->assertJsonPath('versions', ['1.19', '1.18']);
        $this->postJson("/api/knative-regions/{$region->id}/upgrade-serving", ['version' => '1.19'])->assertOk();

        Bus::assertDispatched(UpgradeKnativeServingJob::class, fn ($job) => $job->version === '1.19' && $job->region->is($region));
    }

    /** The upgrade moves the operator first: its next minor need not be one the operator holds yet. */
    public function test_the_next_minor_past_the_operator_stays_on_offer(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        Bus::fake([UpgradeKnativeServingJob::class]);
        $this->operatorVersion = '1.21.4';
        $this->cluster('35');
        $region = $this->region(['knative_serving_version' => '1.21.0']);

        $this->getJson("/api/knative-regions/{$region->id}/compatible-versions")
            ->assertOk()->assertJsonPath('versions', ['1.22', '1.21', '1.20', '1.19', '1.18']);
        $this->postJson("/api/knative-regions/{$region->id}/upgrade-serving", ['version' => '1.22'])->assertOk();
    }

    /**
     * The operator chart of a new install is picked before the minor and does not follow it, and
     * it holds four Serving minors: a fifth on the list would fail when the operator gets it.
     */
    public function test_a_new_install_is_offered_what_the_operator_can_install(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        $this->cluster('35');
        $region = $this->region(['knative_serving_status' => 'not_installed', 'knative_serving_version' => null]);

        $this->getJson("/api/knative-regions/{$region->id}/compatible-versions")
            ->assertOk()->assertJsonPath('versions', ['1.24', '1.23', '1.22', '1.21']);

        $this->operatorVersion = '1.21.4';
        $this->getJson("/api/knative-regions/{$region->id}/compatible-versions?refresh=1")
            ->assertOk()->assertJsonPath('versions', ['1.21', '1.20', '1.19', '1.18']);

        // a Serving install that failed runs nothing: no next minor of the version it tried
        $region->update(['knative_serving_status' => 'error', 'knative_serving_version' => '1.17.0']);
        $this->getJson("/api/knative-regions/{$region->id}/compatible-versions?refresh=1")
            ->assertOk()->assertJsonPath('versions', ['1.21', '1.20', '1.19', '1.18']);
    }

    /** The running operator stays for a Serving install: a version it does not hold could only fail. */
    public function test_a_serving_install_takes_only_a_version_on_offer(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        Bus::fake([InstallKnativeJob::class]);
        $this->operatorVersion = '1.21.4';
        $this->cluster('35');
        $region = $this->region(['knative_serving_status' => 'not_installed', 'knative_serving_version' => null]);

        foreach (['1.24', '1.22', '1.17', '1.24.0'] as $version) {
            $minor = implode('.', array_slice(explode('.', $version), 0, 2));
            $this->postJson("/api/knative-regions/{$region->id}/install-serving", ['version' => $version])
                ->assertStatus(422)->assertJsonValidationErrors('version')
                ->assertJsonPath('message', "The cluster or its Knative Operator cannot install Knative {$minor} (GET /knative-regions/{id}/compatible-versions lists what they can).");
        }
        $this->postJson("/api/knative-regions/{$region->id}/install-serving", ['version' => 'latest'])
            ->assertStatus(422)->assertJsonValidationErrors('version');
        Bus::assertNotDispatched(InstallKnativeJob::class);
        $this->assertSame('not_installed', $region->fresh()->knative_serving_status, 'nothing queued');

        $this->postJson("/api/knative-regions/{$region->id}/install-serving", ['version' => '1.20.2'])->assertOk();
        Bus::assertDispatched(InstallKnativeJob::class);
        $this->assertSame('1.20.2', $region->fresh()->knative_serving_version);
    }

    public function test_a_serving_install_without_a_version_is_queued_as_it_is(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        Bus::fake([InstallKnativeJob::class]);
        $this->operatorVersion = '1.21.4';
        $this->cluster('35');
        $region = $this->region(['knative_serving_status' => 'not_installed', 'knative_serving_version' => null]);

        $this->postJson("/api/knative-regions/{$region->id}/install-serving", [])->assertOk();

        Bus::assertDispatched(InstallKnativeJob::class);
        $this->assertNull($region->fresh()->knative_serving_version);
    }

    /**
     * What `runr regions knative install REGION serving` does: no version, so the install picks
     * one. Picked for the cluster alone it was 1.24 here, which this operator does not hold.
     */
    public function test_a_serving_install_without_a_version_takes_what_the_running_operator_holds(): void
    {
        $this->operatorVersion = '1.21.4';
        $this->cluster('35');
        $region = $this->region(['knative_serving_status' => 'not_installed', 'knative_serving_version' => null]);
        $this->stopAtTheFirstHelmRepo();

        $this->assertInstallStops(fn () => app(KnativeInstallService::class)->installServing($region));

        $this->assertSame('1.21.0', $region->fresh()->knative_serving_version);
    }

    public function test_without_an_operator_a_serving_install_takes_the_newest_the_cluster_supports(): void
    {
        $this->cluster('35');
        $region = $this->region(['knative_serving_status' => 'not_installed', 'knative_serving_version' => null]);
        $this->stopAtTheFirstHelmRepo();

        $this->assertInstallStops(fn () => app(KnativeInstallService::class)->installServing($region));

        $this->assertSame('1.24.0', $region->fresh()->knative_serving_version);
    }

    /** The install has its version once it reaches Traefik's chart: nothing after that is under test. */
    private function stopAtTheFirstHelmRepo(): void
    {
        $this->app->instance(HelmService::class, new class extends HelmService
        {
            public function __construct() {}

            public function getRepoChartEntries(string $repoName, string $chartName): array
            {
                return [['version' => 'v1.24.0', 'appVersion' => '1.24.0'], ['version' => 'v1.21.4', 'appVersion' => '1.21.4']];
            }

            public function repoAdd(string $name, string $url): HelmResult
            {
                throw new \RuntimeException('stopped at the Helm repository');
            }
        });
    }

    private function assertInstallStops(\Closure $install): void
    {
        try {
            $install();
            $this->fail('the scripted Helm repository stops the install');
        } catch (\RuntimeException $e) {
            $this->assertSame('stopped at the Helm repository', $e->getMessage());
        }
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
