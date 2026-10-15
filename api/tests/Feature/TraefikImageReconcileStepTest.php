<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\TraefikImageStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * region:reconcile moves the Traefik of existing regions from Docker Hub to the reqs mirror
 * (TraefikImageStep): a Helm upgrade of RunR's traefik release with its values reused and only
 * the image keys set, at the chart version it runs — the same Traefik version from another
 * registry, never another version.
 */
class TraefikImageReconcileStepTest extends TestCase
{
    use RefreshDatabase;

    private ?string $traefikImage = 'docker.io/traefik:v3.7.4';

    private string $releaseName = 'traefik';

    /** @var list<list<string>> argv of every helm command */
    private array $helm = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            if (parse_url($request->url(), PHP_URL_PATH) !== '/apis/apps/v1/namespaces/knative-serving/deployments/traefik' || $this->traefikImage === null) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            return Http::response([
                'metadata' => [
                    'name' => 'traefik',
                    'annotations' => ['meta.helm.sh/release-name' => $this->releaseName, 'meta.helm.sh/release-namespace' => 'knative-serving'],
                ],
                'spec' => ['template' => ['spec' => ['containers' => [['name' => 'traefik', 'image' => $this->traefikImage]]]]],
            ]);
        });

        $this->app->instance(HelmService::class, new class(fn (array $argv) => $this->helmRun($argv)) extends HelmService
        {
            public function __construct(private \Closure $runner) {}

            protected function run(array $command): HelmResult
            {
                return ($this->runner)($command);
            }
        });
    }

    /** helm list answers like helm 3.16; upgrade / repo add / repo update succeed */
    private function helmRun(array $argv): HelmResult
    {
        $this->helm[] = $argv;

        return ($argv[1] ?? '') === 'list'
            ? new HelmResult(true, json_encode([['name' => 'traefik', 'namespace' => 'knative-serving', 'status' => 'deployed', 'chart' => 'traefik-40.3.0', 'app_version' => 'v3.7.4']]), '', 0)
            : new HelmResult(true, '', '', 0);
    }

    private function cluster(): ClusterGroup
    {
        $region = KnativeRegion::create([
            'name' => 'eu-1',
            'project_namespace' => 'eu-1',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://cluster.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
            'knative_serving_status' => 'ready',
        ]);

        return new ClusterGroup('uid-c', [new ReconcileTarget($region)], new KubernetesClient($region->kubeconfig));
    }

    /** @return list<list<string>> */
    private function upgrades(): array
    {
        return array_values(array_filter($this->helm, fn ($argv) => ($argv[1] ?? '') === 'upgrade'));
    }

    /** @return array<string, string> --set-json key => value of an upgrade */
    private function setJson(array $argv): array
    {
        $set = [];
        foreach ($argv as $i => $arg) {
            if ($arg === '--set-json') {
                [$key, $value] = explode('=', $argv[$i + 1], 2);
                $set[$key] = $value;
            }
        }

        return $set;
    }

    public function test_docker_hub_traefik_moves_to_the_mirror_at_its_chart_version(): void
    {
        $lines = (new TraefikImageStep)->run($this->cluster(), false);

        $this->assertSame(['upgraded Helm release knative-serving/traefik (chart traefik-40.3.0, values reused): '
            .'image docker.io/traefik:v3.7.4 → ghcr.io/brf-tech/reqs/traefik:v3.7.4 — the Traefik pods roll once'], $lines);

        $this->assertCount(1, $upgrades = $this->upgrades());
        $argv = $upgrades[0];
        $this->assertSame(['traefik', 'traefik/traefik'], [$argv[2], $argv[3]]);
        $this->assertContains('--reuse-values', $argv);
        $this->assertSame('40.3.0', $argv[array_search('--version', $argv, true) + 1]);
        $this->assertSame([
            'image.registry' => '"ghcr.io"',
            'image.repository' => '"brf-tech/reqs/traefik"',
            'image.tag' => '"v3.7.4"',
        ], $this->setJson($argv));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $lines = (new TraefikImageStep)->run($this->cluster(), true);

        $this->assertStringStartsWith('would upgrade Helm release knative-serving/traefik (chart traefik-40.3.0, values reused): image docker.io/traefik:v3.7.4 → ', $lines[0]);
        $this->assertSame([], $this->upgrades());
    }

    public function test_a_traefik_on_the_mirror_is_up_to_date(): void
    {
        $this->traefikImage = 'ghcr.io/brf-tech/reqs/traefik:v3.7.4';

        $this->assertSame([], (new TraefikImageStep)->run($this->cluster(), false));
        $this->assertSame([], $this->helm);
    }

    public function test_another_traefik_version_is_left_alone(): void
    {
        $this->traefikImage = 'docker.io/traefik:v3.5.3';

        $this->assertSame(['left alone: Traefik knative-serving/traefik runs docker.io/traefik:v3.5.3, another Traefik version than ghcr.io/brf-tech/reqs/traefik:v3.7.4'],
            (new TraefikImageStep)->run($this->cluster(), false));
        $this->assertSame([], $this->upgrades());
    }

    public function test_runrs_mirror_under_the_namespace_up_to_0_5_0_moves_to_ghcr_io_brf_tech(): void
    {
        $cluster = $this->cluster();

        // the same version: to the image of this release
        $this->traefikImage = 'ghcr.io/runrsh/reqs/traefik:v3.7.4';
        $this->assertSame(['upgraded Helm release knative-serving/traefik (chart traefik-40.3.0, values reused): '
            .'image ghcr.io/runrsh/reqs/traefik:v3.7.4 → ghcr.io/brf-tech/reqs/traefik:v3.7.4 — the Traefik pods roll once'],
            (new TraefikImageStep)->run($cluster, false));

        // another version (an older chart's): the namespace moves, the version stays
        $older = 'v3.6.9';
        $this->helm = [];
        $this->traefikImage = "ghcr.io/runrsh/reqs/traefik:{$older}";
        $this->assertSame(['upgraded Helm release knative-serving/traefik (chart traefik-40.3.0, values reused): '
            ."image ghcr.io/runrsh/reqs/traefik:{$older} → ghcr.io/brf-tech/reqs/traefik:{$older} — the Traefik pods roll once"],
            (new TraefikImageStep)->run($cluster, false));
        $this->assertSame([
            'image.registry' => '"ghcr.io"',
            'image.repository' => '"brf-tech/reqs/traefik"',
            'image.tag' => '"'.$older.'"',
        ], $this->setJson($this->upgrades()[0]));

        // moved: nothing left to do
        $this->helm = [];
        $this->traefikImage = "ghcr.io/brf-tech/reqs/traefik:{$older}";
        $this->assertSame(["left alone: Traefik knative-serving/traefik runs ghcr.io/brf-tech/reqs/traefik:{$older}, another Traefik version than ghcr.io/brf-tech/reqs/traefik:v3.7.4"],
            (new TraefikImageStep)->run($cluster, false));
        $this->assertSame([], $this->upgrades());
    }

    public function test_a_traefik_that_is_not_runrs_or_missing_is_not_touched(): void
    {
        $this->releaseName = 'ingress';
        $this->assertSame([], (new TraefikImageStep)->run($this->cluster(), false));

        $this->traefikImage = null;
        $this->assertSame([], (new TraefikImageStep)->run($this->cluster(), false));

        $this->assertSame([], $this->helm);
    }

    public function test_the_configured_image_wins(): void
    {
        config(['knative.traefik_image' => 'registry.example.com:5000/mirror/traefik:v3.7.4']);

        (new TraefikImageStep)->run($this->cluster(), false);

        $this->assertSame([
            'image.registry' => '"registry.example.com:5000"',
            'image.repository' => '"mirror/traefik"',
            'image.tag' => '"v3.7.4"',
        ], $this->setJson($this->upgrades()[0]));
    }

    public function test_step_is_part_of_region_reconcile(): void
    {
        $steps = array_map(fn ($s) => $s->name(), app(RegionReconciler::class)->steps());

        $this->assertContains('traefik-image', $steps);
        $this->assertSame(array_search('ingress-trust', $steps) + 1, array_search('traefik-image', $steps));
    }
}
