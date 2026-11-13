<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Models\Observability\LoadTest;
use App\Models\Project;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\KnativeUninstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * A kubeconfig replaced through PUT /knative-regions/{id} is checked like a new region's:
 * reachable, a supported Kubernetes version, the same cluster (another cluster is a migration).
 * The full uninstall (uninstall-knative) resets the operator and Serving statuses, which the
 * region's knative_status is computed from.
 */
class RegionKubeconfigReplaceAndUninstallTest extends TestCase
{
    use ActsWithRoles, BuildsAiFixtures, RefreshDatabase;

    private function kubeconfig(string $server): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => $server, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    /** @var array<string, array{0: string, 1: string}> API server host => [kube-system UID, Kubernetes minor] */
    private array $servers = [];

    /** The API servers that answer: their kube-system UIDs and Kubernetes minor versions */
    private function clusters(array $servers): void
    {
        $this->servers = $servers;
        if ($this->faked) {
            return;
        }
        $this->faked = true;

        Http::fake(function (Request $request) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $path = parse_url($request->url(), PHP_URL_PATH);
            [$uid, $minor] = $this->servers[$host] ?? [null, null];
            if ($uid === null) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            return match ($path) {
                '/version' => Http::response(['major' => '1', 'minor' => $minor]),
                '/api/v1/namespaces/kube-system' => Http::response(['metadata' => ['name' => 'kube-system', 'uid' => $uid]]),
                default => Http::response(['kind' => 'Status', 'code' => 404], 404),
            };
        });
    }

    private bool $faked = false;

    private function region(): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true,
            'kubeconfig' => $this->kubeconfig('https://old.test:6443'),
        ]);
    }

    public function test_a_rotated_kubeconfig_of_the_same_cluster_is_taken(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        $this->clusters(['old.test' => ['uid-a', '32'], 'new.test' => ['uid-a', '33']]);
        $region = $this->region();

        $this->putJson("/api/knative-regions/{$region->id}", ['kubeconfig' => $this->kubeconfig('https://new.test:6443')])->assertOk();

        $this->assertStringContainsString('new.test', $region->fresh()->kubeconfig);
        $this->assertSame('v1.33', $region->fresh()->kubernetes_version, "the version it answered (kubectl's minor for exec)");
    }

    public function test_a_kubeconfig_of_another_cluster_is_refused(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        $this->clusters(['old.test' => ['uid-a', '32'], 'new.test' => ['uid-b', '32']]);
        $region = $this->region();

        $this->putJson("/api/knative-regions/{$region->id}", ['kubeconfig' => $this->kubeconfig('https://new.test:6443')])
            ->assertStatus(422)->assertJsonValidationErrors('kubeconfig');

        $this->assertStringContainsString('old.test', $region->fresh()->kubeconfig);
    }

    public function test_an_unreachable_or_too_old_cluster_is_refused_and_an_expired_old_one_is_no_obstacle(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        $region = $this->region();

        $this->clusters(['old.test' => ['uid-a', '32']]);
        $this->putJson("/api/knative-regions/{$region->id}", ['kubeconfig' => $this->kubeconfig('https://gone.test:6443')])
            ->assertStatus(422)->assertJsonValidationErrors('kubeconfig');

        $this->clusters(['old.test' => ['uid-a', '32'], 'new.test' => ['uid-a', '28']]);
        $this->putJson("/api/knative-regions/{$region->id}", ['kubeconfig' => $this->kubeconfig('https://new.test:6443')])
            ->assertStatus(422)->assertJsonValidationErrors('kubeconfig');

        // the old credentials no longer answer (the reason for the rotation): nothing to compare
        $this->clusters(['new.test' => ['uid-b', '32']]);
        $this->putJson("/api/knative-regions/{$region->id}", ['kubeconfig' => $this->kubeconfig('https://new.test:6443')])->assertOk();
        $this->assertStringContainsString('new.test', $region->fresh()->kubeconfig);
    }

    private const CRDS = '/apis/apiextensions.k8s.io/v1/customresourcedefinitions';

    /** A cluster with a few of Serving's CRDs, of which the operator's step applied all, and $knativeServices. */
    private function clusterWithServingCrds(array $knativeServices = []): KnativeRegion
    {
        $this->app->instance(HelmService::class, new class extends HelmService
        {
            public function uninstall(string $release, string $namespace, string $kubeconfigYaml): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }
        });
        $this->fakeCluster([
            'GET '.self::CRDS => ['items' => [
                ['metadata' => ['name' => 'services.serving.knative.dev']],
                ['metadata' => ['name' => 'podautoscalers.autoscaling.internal.knative.dev']],
                ['metadata' => ['name' => 'images.caching.internal.knative.dev']],
            ]],
            'GET '.self::CRDS.'/services.serving.knative.dev' => ['metadata' => ['name' => 'services.serving.knative.dev']],
            'GET /apis/serving.knative.dev/v1/services' => ['items' => $knativeServices],
        ]);

        return KnativeRegion::create([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_operator_status' => 'ready', 'knative_serving_status' => 'ready', 'knative_serving_version' => '1.20.0',
        ]);
    }

    /** @return list<string> the CRDs deleted, by name */
    private function deletedCrds(): array
    {
        return array_map(fn ($call) => basename($call['path']), $this->k8sCallsTo('DELETE', self::CRDS));
    }

    /** All that the install applied, by the label its CRDs carry: Eventing's and the operator's name themselves. */
    public function test_uninstalling_the_operator_removes_every_serving_crd(): void
    {
        $region = $this->clusterWithServingCrds();

        app(KnativeUninstallService::class)->uninstallOperator($region);

        $this->assertSame([
            'services.serving.knative.dev', 'podautoscalers.autoscaling.internal.knative.dev', 'images.caching.internal.knative.dev',
        ], $this->deletedCrds());
        $list = array_values(array_filter($this->k8sCallsTo('GET', self::CRDS), fn ($call) => $call['path'] === self::CRDS));
        $this->assertSame('labelSelector='.urlencode('app.kubernetes.io/name=knative-serving'), $list[0]['query']);
    }

    /** The operator stays, and the cluster's other regions keep their Knative Services in these CRDs. */
    public function test_uninstalling_serving_keeps_every_crd(): void
    {
        $region = $this->clusterWithServingCrds();

        app(KnativeUninstallService::class)->uninstallServing($region);

        $this->assertSame([], $this->deletedCrds());
    }

    public function test_the_full_uninstall_leaves_no_crd_behind(): void
    {
        $region = $this->clusterWithServingCrds();

        app(KnativeUninstallService::class)->uninstall($region);

        $this->assertCount(3, $this->deletedCrds());
    }

    public function test_the_full_uninstall_keeps_the_crds_of_knative_services_left_on_the_cluster(): void
    {
        $region = $this->clusterWithServingCrds([['metadata' => ['name' => 'shop', 'namespace' => 'eu-2']]]);

        app(KnativeUninstallService::class)->uninstall($region);

        $this->assertSame([], $this->deletedCrds());
        $this->assertSame('not_installed', $region->refresh()->knative_operator_status);
    }

    public function test_the_full_uninstall_resets_the_component_statuses(): void
    {
        $this->app->instance(HelmService::class, new class extends HelmService
        {
            public function uninstall(string $release, string $namespace, string $kubeconfigYaml): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }
        });
        // RunR's load-test namespace on the cluster (docs/load-test.md)
        $this->fakeCluster(['GET /api/v1/namespaces/runr-loadtest' => ['metadata' => ['name' => 'runr-loadtest', 'labels' => [
            'app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/component' => 'load-test',
        ]]]]);
        $region = KnativeRegion::create([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_operator_status' => 'ready', 'knative_serving_status' => 'ready', 'knative_serving_version' => '1.20.0',
        ]);
        $this->assertSame('ready', $region->knative_status);
        $test = $this->runningLoadTest($region);

        app(KnativeUninstallService::class)->uninstall($region);

        $region->refresh();
        $this->assertSame('not_installed', $region->knative_operator_status);
        $this->assertSame('not_installed', $region->knative_serving_status);
        $this->assertNull($region->knative_serving_version);
        $this->assertSame('not_installed', $region->knative_status);

        // the load tests' namespace went with the ingress they target, the region's tests ended
        $this->assertCount(1, $this->k8sCallsTo('DELETE', '/api/v1/namespaces/runr-loadtest'));
        $this->assertSame(LoadTest::FAILED, $test->fresh()->status);
        $this->assertStringContainsString('Removed the load-test namespace (1 running test(s) stopped).', (string) $region->knative_status_message);
    }

    public function test_uninstalling_serving_removes_the_load_test_namespace(): void
    {
        $this->app->instance(HelmService::class, new class extends HelmService
        {
            public function uninstall(string $release, string $namespace, string $kubeconfigYaml): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }
        });
        $this->fakeCluster(['GET /api/v1/namespaces/runr-loadtest' => ['metadata' => ['name' => 'runr-loadtest', 'labels' => [
            'app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/component' => 'load-test',
        ]]]]);
        $region = KnativeRegion::create([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_operator_status' => 'ready', 'knative_serving_status' => 'ready', 'knative_serving_version' => '1.20.0',
        ]);
        $test = $this->runningLoadTest($region);

        app(KnativeUninstallService::class)->uninstallServing($region);

        $this->assertSame('not_installed', $region->refresh()->knative_serving_status);
        $this->assertCount(1, $this->k8sCallsTo('DELETE', '/api/v1/namespaces/runr-loadtest'));
        $this->assertSame(LoadTest::FAILED, $test->fresh()->status);
        $this->assertSame(__('observability.load_test.region_uninstalled'), $test->fresh()->error);
    }

    private function runningLoadTest(KnativeRegion $region): LoadTest
    {
        $project = Project::create(['name' => 'orders', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        return tap((new LoadTest)->forceFill([
            'project_id' => $project->id, 'region_id' => $region->id, 'status' => LoadTest::RUNNING, 'host' => 'orders.eu.runr.test',
            'path' => '/', 'users' => 10, 'duration_seconds' => 30, 'job_name' => 'lt-x', 'namespace' => 'runr-loadtest', 'started_at' => now(),
        ]))->save();
    }
}
