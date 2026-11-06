<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\Contracts\KnativeConstants;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\Helpers\TraefikChartValues;
use App\Services\KnativeInstallService;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\TraefikChartStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * region:reconcile moves the Traefik of existing regions to the pinned chart (TraefikChartStep):
 * its own values rewritten for the new chart, the pinned image, an atomic upgrade. `--reuse-values`
 * cannot do it: the chart's schema refuses the logging keys the release keeps. The move also makes
 * Traefik delete request headers with aliasing names, which an earlier chart cannot.
 */
class TraefikChartReconcileStepTest extends TestCase
{
    use RefreshDatabase;

    private const KEEP_WARNING = 'warning: Traefik knative-serving/traefik keeps request headers with aliasing names (knative.traefikAliasHeadersStrategy: keep): an underscore '
        .'or dot alias of X-Runr-Caller or X-Runr-Consumer can reach workloads, and servers that fold header names read it as the '
        .'verified header — a stopgap while an app renames its headers';

    private const STORED = '{"logs":{"general":{"level":"INFO"},"access":{"enabled":true,"filters":{},"fields":{"general":{"defaultMode":"keep"}}}},'
        .'"image":{"registry":"ghcr.io","repository":"brf-tech/runr/reqs/traefik","tag":"v3.7.4"},'
        .'"ports":{"web":{"proxyProtocol":{"trustedIPs":[],"insecure":false}}}}';

    private ?string $traefikImage = 'ghcr.io/brf-tech/runr/reqs/traefik:v3.7.4';

    /** @var list<string> the Traefik container's args */
    private array $traefikArgs = ['--entryPoints.web.address=:8000/tcp'];

    private string $releaseName = 'traefik';

    private ?string $deployedChart = 'traefik-40.3.0';

    private string $deployedStatus = 'deployed';

    private string $k8sMinor = '31';

    private ?string $storedValues = self::STORED;

    /** @var list<HelmResult> what the next helm upgrades answer */
    private array $upgradeResults = [];

    /** Minutes an upgrade takes */
    private int $upgradeMinutes = 0;

    /** @var list<array<string, mixed>>|null helm history; null: release not found */
    private ?array $history = null;

    /** @var array<string, int> helm subcommand => the time limit of its process */
    private array $processTimeouts = [];

    /** @var list<list<string>> argv of every helm command */
    private array $helm = [];

    /** @var list<string> the --values files of the upgrades, read while they exist */
    private array $valuesFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/version') {
                return Http::response(['major' => '1', 'minor' => $this->k8sMinor]);
            }
            if (str_starts_with((string) $path, '/apis/rbac.authorization.k8s.io/')) {
                return Http::response(['kind' => 'Status', 'code' => 200]);   // installTraefik's Knative RBAC
            }
            if (! preg_match('#^/apis/apps/v1/namespaces/([^/]+)/deployments/traefik$#', (string) $path, $m) || $this->traefikImage === null) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            return Http::response([
                'metadata' => [
                    'name' => 'traefik',
                    'annotations' => ['meta.helm.sh/release-name' => $this->releaseName, 'meta.helm.sh/release-namespace' => $m[1]],
                ],
                'spec' => ['template' => ['spec' => ['containers' => [['name' => 'traefik', 'image' => $this->traefikImage, 'args' => $this->traefikArgs]]]]],
            ]);
        });

        $this->app->instance(HelmService::class, new class(fn (array $argv, int $timeout) => $this->helmRun($argv, $timeout)) extends HelmService
        {
            public function __construct(private \Closure $runner) {}

            protected function run(array $command): HelmResult
            {
                return ($this->runner)($command, $this->processTimeout);
            }
        });
    }

    private function helmRun(array $argv, int $timeout): HelmResult
    {
        $this->helm[] = $argv;
        $this->processTimeouts[$argv[1] ?? ''] = $timeout;

        return match ($argv[1] ?? '') {
            'list' => new HelmResult(true, json_encode($this->deployedChart === null ? [] : [['name' => 'traefik', 'namespace' => 'knative-serving', 'status' => $this->deployedStatus, 'chart' => $this->deployedChart]]), '', 0),
            'get' => $this->storedValues === null ? new HelmResult(false, '', 'release: not found', 1) : new HelmResult(true, $this->storedValues, '', 0),
            'upgrade' => $this->upgrade($argv),
            'history' => $this->history === null ? new HelmResult(false, '', 'Error: release: not found', 1) : new HelmResult(true, (string) json_encode($this->history), '', 0),
            'rollback' => $this->rollback(),
            default => new HelmResult(true, '', '', 0),
        };
    }

    private function rollback(): HelmResult
    {
        $this->deployedChart = 'traefik-40.3.0';

        return new HelmResult(true, 'Rollback was a success! Happy Helming!', '', 0);
    }

    private function upgrade(array $argv): HelmResult
    {
        if (in_array('--values', $argv, true)) {
            $this->valuesFiles[] = (string) file_get_contents($argv[array_search('--values', $argv, true) + 1]);
        }
        $this->travel($this->upgradeMinutes)->minutes();

        return array_shift($this->upgradeResults) ?? new HelmResult(true, '', '', 0);
    }

    /** @param  list<string>  $namespaces  serving namespaces: one region each */
    private function cluster(array $namespaces = ['knative-serving']): ClusterGroup
    {
        $regions = array_map(fn (string $namespace) => $this->region($namespace), $namespaces);

        return new ClusterGroup('uid-c', array_map(fn (KnativeRegion $region) => new ReconcileTarget($region), $regions), new KubernetesClient($regions[0]->kubeconfig));
    }

    private function region(string $namespace): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => "eu-{$namespace}",
            'project_namespace' => "eu-{$namespace}",
            'knative_serving_namespace' => $namespace,
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
    }

    /** @return list<list<string>> */
    private function upgrades(): array
    {
        return array_values(array_filter($this->helm, fn ($argv) => ($argv[1] ?? '') === 'upgrade'));
    }

    private function pinned(): string
    {
        return 'traefik-'.KnativeConstants::TRAEFIK_CHART_VERSION;
    }

    public function test_a_region_moves_to_the_pinned_chart_with_its_values_rewritten(): void
    {
        $lines = (new TraefikChartStep)->run($this->cluster(), false);

        $this->assertSame(["upgraded Helm release knative-serving/traefik from chart traefik-40.3.0 to {$this->pinned()}, its values kept: "
            .'image ghcr.io/brf-tech/runr/reqs/traefik:v3.7.4 → '.KnativeConstants::TRAEFIK_IMAGE
            .', request headers with aliasing names deleted — the Traefik pods roll once'], $lines);

        $this->assertCount(1, $upgrades = $this->upgrades());
        $argv = $upgrades[0];
        $this->assertSame(['traefik', 'traefik/traefik'], [$argv[2], $argv[3]]);
        $this->assertSame(KnativeConstants::TRAEFIK_CHART_VERSION, $argv[array_search('--version', $argv, true) + 1]);
        $this->assertContains('--atomic', $argv, 'pods that do not become ready bring the release back');
        $this->assertNotContains('--reuse-values', $argv, 'the chart\'s schema refuses the release\'s logging keys');

        $values = json_decode($this->valuesFiles[0], true);
        $this->assertArrayNotHasKey('logs', $values);
        $this->assertSame(['level' => 'INFO'], $values['log']);
        $this->assertSame('keep', $values['accessLog']['fields']['defaultMode']);
        $this->assertSame('v3.7.17', $values['image']['tag']);
        $this->assertSame('delete', $values['global']['aliasHeadersStrategy']);
        // an empty list stays one (the chart's schema refuses a map there), an empty map stays one
        $this->assertStringContainsString('"trustedIPs":[]', $this->valuesFiles[0]);
        $this->assertStringContainsString('"filters":{}', $this->valuesFiles[0]);
    }

    public function test_the_values_file_is_gone_after_the_upgrade(): void
    {
        (new TraefikChartStep)->run($this->cluster(), false);

        $argv = $this->upgrades()[0];
        $this->assertFileDoesNotExist($argv[array_search('--values', $argv, true) + 1]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $lines = (new TraefikChartStep)->run($this->cluster(), true);

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith("would upgrade Helm release knative-serving/traefik from chart traefik-40.3.0 to {$this->pinned()}, its values kept: ", $lines[0]);
        $this->assertSame([], $this->upgrades());
    }

    public function test_a_region_on_the_pinned_chart_that_deletes_aliases_stays_as_it_is(): void
    {
        $this->deployedChart = $this->pinned();
        $this->traefikArgs[] = TraefikChartValues::ALIAS_HEADERS_ARG.'delete';

        $this->assertSame([], (new TraefikChartStep)->run($this->cluster(), false));
        $this->assertSame([], $this->upgrades());
    }

    public function test_a_region_on_the_pinned_chart_without_the_flag_starts_deleting_aliases(): void
    {
        $this->deployedChart = $this->pinned();

        $lines = (new TraefikChartStep)->run($this->cluster(), false);

        $this->assertSame(["upgraded Helm release knative-serving/traefik (chart {$this->pinned()}, values reused): "
            .'request headers with aliasing names deleted on every entry point — the Traefik pods roll once'], $lines);
        $argv = $this->upgrades()[0];
        $this->assertContains('--reuse-values', $argv, 'nothing else moves');
        $this->assertContains('global.aliasHeadersStrategy="delete"', $argv);
    }

    public function test_changing_the_value_to_keep_moves_a_region_on_the_pinned_chart(): void
    {
        config(['knative.traefik_alias_headers_strategy' => 'keep']);
        $this->deployedChart = $this->pinned();
        $this->traefikArgs[] = TraefikChartValues::ALIAS_HEADERS_ARG.'delete';

        $lines = (new TraefikChartStep)->run($this->cluster(), false);

        $this->assertSame(["upgraded Helm release knative-serving/traefik (chart {$this->pinned()}, values reused): "
            .'request headers with aliasing names kept on every entry point — the Traefik pods roll once', self::KEEP_WARNING], $lines);
        $this->assertContains('global.aliasHeadersStrategy="keep"', $this->upgrades()[0]);
    }

    public function test_changing_the_value_back_to_delete_moves_it_again_and_the_warning_ends(): void
    {
        $this->deployedChart = $this->pinned();
        $this->traefikArgs[] = TraefikChartValues::ALIAS_HEADERS_ARG.'keep';

        $lines = (new TraefikChartStep)->run($this->cluster(), false);

        $this->assertSame(["upgraded Helm release knative-serving/traefik (chart {$this->pinned()}, values reused): "
            .'request headers with aliasing names deleted on every entry point — the Traefik pods roll once'], $lines);
        $this->assertContains('global.aliasHeadersStrategy="delete"', $this->upgrades()[0]);
    }

    public function test_a_region_that_keeps_aliases_as_configured_warns_on_every_run_and_changes_nothing(): void
    {
        config(['knative.traefik_alias_headers_strategy' => 'keep']);
        $this->deployedChart = $this->pinned();
        $this->traefikArgs[] = TraefikChartValues::ALIAS_HEADERS_ARG.'keep';

        $cluster = $this->cluster();
        $this->assertSame([self::KEEP_WARNING], (new TraefikChartStep)->run($cluster, false));
        $this->assertSame([self::KEEP_WARNING], (new TraefikChartStep)->run($cluster, false));
        $this->assertSame([self::KEEP_WARNING], (new TraefikChartStep)->run($cluster, true), 'and in a dry run');
        $this->assertSame([], $this->upgrades());
    }

    /** No argument is Traefik's own default: nothing to move, and just as open. */
    public function test_keep_on_a_traefik_without_the_flag_needs_no_upgrade_but_warns(): void
    {
        config(['knative.traefik_alias_headers_strategy' => 'keep']);
        $this->deployedChart = $this->pinned();

        $this->assertSame([self::KEEP_WARNING], (new TraefikChartStep)->run($this->cluster(), false));
        $this->assertSame([], $this->upgrades());
    }

    public function test_a_region_moved_under_keep_takes_the_value_and_warns(): void
    {
        config(['knative.traefik_alias_headers_strategy' => 'keep']);

        $lines = (new TraefikChartStep)->run($this->cluster(), false);

        $this->assertStringEndsWith('request headers with aliasing names kept — the Traefik pods roll once', $lines[0]);
        $this->assertSame(self::KEEP_WARNING, $lines[1]);
        $this->assertSame('keep', json_decode($this->valuesFiles[0], true)['global']['aliasHeadersStrategy']);
    }

    public function test_the_dry_run_of_a_move_under_keep_warns_too(): void
    {
        config(['knative.traefik_alias_headers_strategy' => 'keep']);

        $lines = (new TraefikChartStep)->run($this->cluster(), true);

        $this->assertStringStartsWith('would upgrade Helm release', $lines[0]);
        $this->assertSame(self::KEEP_WARNING, $lines[1]);
        $this->assertSame([], $this->upgrades());
    }

    public function test_only_keep_by_name_leaves_the_default(): void
    {
        foreach (['reject', '', null, 'KEEP'] as $value) {
            config(['knative.traefik_alias_headers_strategy' => $value]);
            $this->assertSame('delete', TraefikChartValues::aliasStrategy(), var_export($value, true));
        }

        config(['knative.traefik_alias_headers_strategy' => 'keep']);
        $this->assertSame('keep', TraefikChartValues::aliasStrategy());
    }

    public function test_a_new_region_takes_the_configured_strategy(): void
    {
        foreach (['delete', 'keep'] as $strategy) {
            config(['knative.traefik_alias_headers_strategy' => $strategy]);
            $service = (new \ReflectionClass(KnativeInstallService::class))->newInstanceWithoutConstructor();
            (new \ReflectionMethod($service, 'installTraefik'))->invoke(
                $service, new KubernetesClient($this->region("install-{$strategy}")->kubeconfig), app(HelmService::class), 'knative-serving', 'kubeconfig',
            );

            $this->assertSame($strategy, Yaml::parse((string) end($this->valuesFiles))['global']['aliasHeadersStrategy']);
        }
    }

    /**
     * A rollback that never started: the failed upgrade stays the newest revision, so helm list
     * reads the pinned chart. The pods may not run it: that is no move done.
     */
    public function test_a_failed_revision_of_the_pinned_chart_is_moved_again(): void
    {
        $this->deployedChart = $this->pinned();
        $this->deployedStatus = 'failed';
        $this->traefikArgs[] = TraefikChartValues::ALIAS_HEADERS_ARG.'delete';
        $this->storedValues = '{"log":{"level":"INFO"},"accessLog":{"enabled":true},"image":{"registry":"ghcr.io","repository":"brf-tech/runr/reqs/traefik","tag":"v3.7.17"}}';

        $lines = (new TraefikChartStep)->run($this->cluster(), false);

        $this->assertStringStartsWith("upgraded Helm release knative-serving/traefik to chart {$this->pinned()} again, its last upgrade failed, its values kept: ", $lines[0]);
        $this->assertCount(1, $upgrades = $this->upgrades());
        $this->assertContains('--atomic', $upgrades[0]);
        $this->assertSame(['level' => 'INFO'], json_decode($this->valuesFiles[0], true)['log'], 'values already rewritten stay');
    }

    public function test_a_failed_revision_that_fails_again_fails_the_step(): void
    {
        $this->deployedChart = $this->pinned();
        $this->deployedStatus = 'failed';
        $this->traefikArgs[] = TraefikChartValues::ALIAS_HEADERS_ARG.'delete';
        $this->upgradeResults = [new HelmResult(false, '', 'timed out waiting for the condition', 1)];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("helm upgrade of knative-serving/traefik to chart {$this->pinned()} failed");

        (new TraefikChartStep)->run($this->cluster(), false);
    }

    public function test_the_dry_run_says_a_failed_revision_is_moved_again(): void
    {
        $this->deployedChart = $this->pinned();
        $this->deployedStatus = 'failed';
        $this->traefikArgs[] = TraefikChartValues::ALIAS_HEADERS_ARG.'delete';

        $lines = (new TraefikChartStep)->run($this->cluster(), true);

        $this->assertStringStartsWith("would upgrade Helm release knative-serving/traefik to chart {$this->pinned()} again, its last upgrade failed", $lines[0]);
        $this->assertSame([], $this->upgrades());
    }

    /** A rollback that ran and failed: its own revision is the newest, with the chart it went back to. */
    public function test_a_failed_rollback_revision_of_the_earlier_chart_moves_as_usual(): void
    {
        $this->deployedStatus = 'failed';

        $lines = (new TraefikChartStep)->run($this->cluster(), false);

        $this->assertStringStartsWith("upgraded Helm release knative-serving/traefik from chart traefik-40.3.0 to {$this->pinned()}", $lines[0]);
        $this->assertArrayNotHasKey('logs', json_decode($this->valuesFiles[0], true));
    }

    public function test_a_chart_before_40_is_left_alone_with_a_warning(): void
    {
        $this->deployedChart = 'traefik-39.0.9';

        $this->assertSame(["warning: Traefik knative-serving/traefik stays on chart traefik-39.0.9, RunR moves charts from 40 up to {$this->pinned()}: "
            .'request headers with aliasing names keep reaching its workloads'],
            (new TraefikChartStep)->run($this->cluster(), false));
        $this->assertSame([], $this->upgrades());
    }

    public function test_a_newer_chart_is_not_taken_back(): void
    {
        $this->deployedChart = 'traefik-999.0.0';

        $this->assertStringStartsWith('warning: ', (new TraefikChartStep)->run($this->cluster(), false)[0]);
        $this->assertSame([], $this->upgrades());
    }

    public function test_a_cluster_below_the_charts_kubernetes_is_left_alone_with_a_warning(): void
    {
        $this->k8sMinor = '29';

        $this->assertSame(["warning: Traefik knative-serving/traefik stays on chart traefik-40.3.0, chart {$this->pinned()} needs Kubernetes 1.30 or later "
            .'(the cluster runs 1.29): request headers with aliasing names keep reaching its workloads'],
            (new TraefikChartStep)->run($this->cluster(), false));
        $this->assertSame([], $this->upgrades());
    }

    /** The chart refuses the strategy on that image: a failing step on every reconcile would say less. */
    public function test_an_image_override_older_than_the_strategy_is_left_alone_with_a_warning(): void
    {
        config(['knative.traefik_image' => 'registry.example.com/mirror/traefik:v3.7.4']);

        $this->assertSame(['warning: Traefik knative-serving/traefik stays on chart traefik-40.3.0: registry.example.com/mirror/traefik:v3.7.4 (knative.traefikImage) '
            .'is older than v3.7.17, the first Traefik that deletes request headers with aliasing names on every entry point — '
            .'request headers with aliasing names keep reaching its workloads'],
            (new TraefikChartStep)->run($this->cluster(), false));
        $this->assertSame([], $this->upgrades());
    }

    public function test_an_image_override_of_the_pinned_version_moves_with_the_chart(): void
    {
        config(['knative.traefik_image' => 'registry.example.com:5000/mirror/traefik:v3.7.17']);

        (new TraefikChartStep)->run($this->cluster(), false);

        $this->assertSame(
            ['registry' => 'registry.example.com:5000', 'repository' => 'mirror/traefik', 'tag' => 'v3.7.17'],
            json_decode($this->valuesFiles[0], true)['image'],
        );
    }

    public function test_a_release_helm_does_not_list_is_reported(): void
    {
        $this->deployedChart = null;

        $lines = (new TraefikChartStep)->run($this->cluster(), false);

        $this->assertStringStartsWith('left alone: cannot determine deployed chart version of Helm release knative-serving/traefik', $lines[0]);
        $this->assertSame([], $this->upgrades());
    }

    /** helm history of a release whose last revision is pending since $minutesAgo */
    private function interrupted(string $status, int $minutesAgo): void
    {
        $this->deployedChart = null;   // helm list hides it
        $this->history = [
            ['revision' => 4, 'updated' => '2026-09-30T08:01:44.118904203Z', 'status' => 'deployed', 'chart' => 'traefik-40.3.0', 'app_version' => 'v3.7.4', 'description' => 'Upgrade complete'],
            ['revision' => 5, 'updated' => now()->subMinutes($minutesAgo)->format('Y-m-d\TH:i:s.uP'), 'status' => $status, 'chart' => $this->pinned(), 'app_version' => 'v3.7.17', 'description' => 'Preparing upgrade'],
        ];
    }

    /** A pod of the Job killed in the upgrade leaves the release pending, which helm list hides. */
    public function test_a_release_a_cut_off_run_left_pending_is_rolled_back_and_moved(): void
    {
        $this->interrupted('pending-upgrade', 20);

        $lines = (new TraefikChartStep)->run($this->cluster(), false);

        $this->assertStringStartsWith('upgraded Helm release knative-serving/traefik from chart traefik-40.3.0', $lines[0]);
        $this->assertCount(1, array_filter($this->helm, fn ($argv) => ($argv[1] ?? '') === 'rollback'));
        $this->assertCount(1, $this->upgrades());
    }

    public function test_a_pending_operation_that_may_still_run_fails_the_step_for_the_next_attempt(): void
    {
        $this->interrupted('pending-rollback', 5);

        try {
            (new TraefikChartStep)->run($this->cluster(), false);
            $this->fail('the step must fail: an --atomic rollback may still run');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('another helm operation may still run on it', $e->getMessage());
        }
        $this->assertSame([], $this->upgrades());
    }

    public function test_the_dry_run_touches_no_pending_release(): void
    {
        $this->interrupted('pending-upgrade', 20);

        $lines = (new TraefikChartStep)->run($this->cluster(), true);

        $this->assertStringStartsWith('left alone: cannot determine deployed chart version', $lines[0]);
        $this->assertCount(0, array_filter($this->helm, fn ($argv) => ($argv[1] ?? '') === 'rollback'));
    }

    /** --atomic's wait and its rollback each take --timeout: 660 s would kill helm in the rollback. */
    public function test_helm_gets_the_time_of_the_wait_and_the_rollback(): void
    {
        (new TraefikChartStep)->run($this->cluster(), false);

        $argv = $this->upgrades()[0];
        $this->assertSame('10m', $argv[array_search('--timeout', $argv, true) + 1]);
        $this->assertGreaterThan(2 * 600, HelmService::CHART_UPGRADE_SECONDS);
        $this->assertSame(HelmService::CHART_UPGRADE_SECONDS, $this->processTimeouts['upgrade']);
        $this->assertSame(660, $this->processTimeouts['get'], 'only this upgrade');
    }

    public function test_the_time_limit_is_back_after_the_upgrade(): void
    {
        $helm = app(HelmService::class);
        $helm->upgradeToChartVersion('traefik', 'traefik/traefik', 'knative-serving', 'kubeconfig', '42.3.0', '{}');
        $helm->repoUpdate();

        $this->assertSame(660, $this->processTimeouts['repo']);
    }

    public function test_several_namespaces_that_fit_move_in_one_step(): void
    {
        $this->upgradeMinutes = 5;

        $lines = (new TraefikChartStep)->run($this->cluster(['serving-a', 'serving-b']), false);

        $this->assertCount(2, $this->upgrades());
        $this->assertStringStartsWith('upgraded Helm release serving-a/traefik', $lines[0]);
        $this->assertStringStartsWith('upgraded Helm release serving-b/traefik', $lines[1]);
    }

    /** The Job's time limit leaves a step 1800 s past the deadline: an upgrade must not be cut there. */
    public function test_no_upgrade_starts_that_could_outlast_the_steps_time(): void
    {
        $this->upgradeMinutes = 15;

        $lines = (new TraefikChartStep)->run($this->cluster(['serving-a', 'serving-b']), false);

        $this->assertCount(1, $this->upgrades());
        $this->assertStringStartsWith('upgraded Helm release serving-a/traefik', $lines[0]);
        $this->assertSame('waiting: Helm release serving-b/traefik stays on chart traefik-40.3.0 in this attempt, '
            .'another upgrade of this step might outlast its time limit', $lines[1]);
    }

    public function test_the_steps_time_is_what_the_job_gives_it(): void
    {
        $job = dirname(__DIR__, 3).'/helm/templates/region-reconcile-job.yaml';
        if (! is_file($job)) {
            $this->markTestSkipped('helm/ is not part of this checkout');
        }

        $this->assertStringContainsString(
            '$reconcileLimit := add $reconcileDeadline '.TraefikChartStep::STEP_SECONDS,
            (string) file_get_contents($job),
        );
        $this->assertLessThanOrEqual(TraefikChartStep::STEP_SECONDS, HelmService::CHART_UPGRADE_SECONDS, 'one upgrade fits');
    }

    public function test_a_traefik_of_another_owner_is_none_of_its_business(): void
    {
        $this->releaseName = 'someone-elses';

        $this->assertSame([], (new TraefikChartStep)->run($this->cluster(), false));
        $this->assertSame([], $this->helm);
    }

    public function test_no_traefik_no_work(): void
    {
        $this->traefikImage = null;

        $this->assertSame([], (new TraefikChartStep)->run($this->cluster(), false));
        $this->assertSame([], $this->helm);
    }

    public function test_a_failed_upgrade_fails_the_step_and_says_where_the_release_is(): void
    {
        $this->upgradeResults = [new HelmResult(false, '', 'release traefik failed, and has been rolled back due to atomic being set', 1)];

        try {
            (new TraefikChartStep)->run($this->cluster(), false);
            $this->fail('the step must fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("helm upgrade of knative-serving/traefik to chart {$this->pinned()} failed, it stays on traefik-40.3.0", $e->getMessage());
            $this->assertStringContainsString('rolled back due to atomic', $e->getMessage());
        }
    }

    public function test_values_helm_cannot_read_fail_the_step_before_any_upgrade(): void
    {
        $this->storedValues = null;

        try {
            (new TraefikChartStep)->run($this->cluster(), false);
            $this->fail('the step must fail');
        } catch (RuntimeException $e) {
            $this->assertSame('cannot read the values of Helm release knative-serving/traefik', $e->getMessage());
        }
        $this->assertSame([], $this->upgrades());
    }

    public function test_it_runs_before_traefik_image_which_would_only_call_the_new_version_another_one(): void
    {
        $steps = array_map(fn ($step) => $step->name(), app(RegionReconciler::class)->steps());

        $this->assertLessThan(array_search('traefik-image', $steps, true), array_search('traefik-chart', $steps, true));
    }
}
