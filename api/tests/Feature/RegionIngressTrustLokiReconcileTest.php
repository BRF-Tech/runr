<?php

namespace Tests\Feature;

use App\Jobs\ApplyIngressTrustJob;
use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\Helpers\ManagedLoki;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\RegionReconcile\RegionReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * 0.4.1 region-side changes brought to existing regions by `region:reconcile` (and, for the
 * ingress trust, by a settings change):
 *  - ingress-trust: the region Traefik trusts PROXY protocol / X-Forwarded-* only from the
 *    regions' ingress_trusted_ips (was 0.0.0.0/0) — Helm upgrade of RunR's traefik release;
 *  - loki-deletion: RunR's loki-stack gets the delete API log retention needs — Helm upgrade,
 *    never for a reused Loki.
 * Both upgrade with --reuse-values at the chart version the release runs, read from `helm list`:
 * `helm status -o json` carries no chart. The helm binary is replaced by a
 * recorder that answers with the JSON shapes helm 3.16 prints.
 */
class RegionIngressTrustLokiReconcileTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_TRUST_ARGS = [
        '--entryPoints.web.proxyProtocol.trustedIPs=0.0.0.0/0',
        '--entryPoints.web.forwardedHeaders.trustedIPs=0.0.0.0/0',
        '--entryPoints.websecure.proxyProtocol.trustedIPs=0.0.0.0/0',
        '--entryPoints.websecure.forwardedHeaders.trustedIPs=0.0.0.0/0',
    ];

    /** @var array<string, mixed> fake cluster state */
    private array $cluster = [];

    /** @var list<string> "METHOD path" of every API call */
    private array $calls = [];

    /** @var array<string, string> release => chart column of `helm list -o json` ("" = not listed) */
    private array $helmCharts = ['traefik' => 'traefik-40.3.0', 'loki-stack' => 'loki-stack-2.10.3'];

    private bool $helmUpgradeFails = false;

    /** @var array<string, list<array>> release => `helm history -o json` rows (none: helm answers nothing) */
    private array $helmHistory = [];

    /** @var array<string, string> release => chart its rollback deploys again */
    private array $helmPendingChart = [];

    /** @var list<array{argv: list<string>, kubeconfig: ?string}> every helm command */
    private array $helmCommands = [];

    /**
     * `helm status loki-stack -n observability -o json` of helm 3.16: the release without its
     * chart (cmd/helm/status.go strips it) — name, info, config (user values), manifest, version
     * (the revision), namespace.
     */
    private const HELM_316_STATUS = [
        'name' => 'loki-stack',
        'info' => [
            'first_deployed' => '2026-09-02T09:14:03.402156871Z',
            'last_deployed' => '2026-09-30T08:01:44.118904203Z',
            'deleted' => '',
            'description' => 'Upgrade complete',
            'status' => 'deployed',
        ],
        'config' => ['loki' => ['enabled' => true, 'persistence' => ['enabled' => true, 'size' => '20Gi']], 'grafana' => ['enabled' => false], 'prometheus' => ['enabled' => false]],
        'manifest' => "---\n# Source: loki-stack/charts/loki/templates/secret.yaml\napiVersion: v1\nkind: Secret\n",
        'version' => 2,
        'namespace' => 'observability',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);
        // the tracing step installs no backend on these regions (TracingBackendReconcileStepTest)
        config(['observability.tracing.install_with_serving' => false]);
        RegionReconciler::clearRegionSources();

        $this->cluster = [
            'traefikRelease' => 'traefik',
            // read / write timeouts already follow the default request_timeout (RequestTimeoutStep
            // has nothing to do here: RegionRequestTimeoutTest)
            'traefikArgs' => array_merge(['--entryPoints.web.address=:8000/tcp', '--providers.knative'], self::OLD_TRUST_ARGS, [
                '--entryPoints.web.transport.respondingTimeouts.readTimeout=310s',
                '--entryPoints.web.transport.respondingTimeouts.writeTimeout=310s',
                '--entryPoints.websecure.transport.respondingTimeouts.readTimeout=310s',
                '--entryPoints.websecure.transport.respondingTimeouts.writeTimeout=310s',
            ]),
            'lokiConfig' => ['compactor' => ['working_directory' => '/data/loki/boltdb-shipper-compactor', 'shared_store' => 'filesystem'], 'limits_config' => ['reject_old_samples' => true]],
        ];
    }

    protected function tearDown(): void
    {
        RegionReconciler::clearRegionSources();
        parent::tearDown();
    }

    private function fakeCluster(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = $request->method().' '.$path;
            $c = $this->cluster;

            return match ($path) {
                '/version' => Http::response(['major' => '1', 'minor' => '31']),
                '/api/v1/namespaces/kube-system' => Http::response(['metadata' => ['uid' => 'uid-cluster']]),
                '/apis/apps/v1/namespaces/knative-serving/deployments/traefik' => $c['traefikRelease'] === null
                    ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                    : Http::response([
                        'metadata' => [
                            'name' => 'traefik',
                            'annotations' => ['meta.helm.sh/release-name' => $c['traefikRelease'], 'meta.helm.sh/release-namespace' => 'knative-serving'],
                            'labels' => ['helm.sh/chart' => 'traefik-40.3.0', 'app.kubernetes.io/managed-by' => 'Helm'],
                        ],
                        'spec' => ['template' => ['spec' => ['containers' => [['name' => 'traefik', 'args' => $c['traefikArgs']]]]]],
                    ]),
                '/api/v1/namespaces/observability/secrets/loki-stack' => $c['lokiConfig'] === null
                    ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                    : Http::response([
                        'metadata' => ['name' => 'loki-stack', 'labels' => ['app' => 'loki', 'chart' => 'loki-2.16.0', 'release' => 'loki-stack']],
                        'data' => ['loki.yaml' => base64_encode(Yaml::dump($c['lokiConfig'], 4))],
                    ]),
                default => Http::response(['kind' => 'Status', 'code' => 404], 404),
            };
        });
    }

    /** HelmService with the helm binary replaced by helmRun() */
    private function fakeHelm(): void
    {
        $this->app->instance(HelmService::class, new class(fn (array $argv) => $this->helmRun($argv)) extends HelmService
        {
            public function __construct(private \Closure $runner) {}

            protected function run(array $command): HelmResult
            {
                return ($this->runner)($command);
            }
        });
    }

    private function helmRun(array $argv): HelmResult
    {
        $flag = fn (string $name) => ($i = array_search($name, $argv, true)) === false ? null : ($argv[$i + 1] ?? null);
        $path = $flag('--kubeconfig');
        $this->helmCommands[] = ['argv' => $argv, 'kubeconfig' => $path && is_file($path) ? file_get_contents($path) : null];

        if (($argv[1] ?? '') === 'rollback') {
            // the release is deployed again at the revision it was rolled back to
            $this->helmCharts[$argv[2]] = $this->helmPendingChart[$argv[2]] ?? 'traefik-40.3.0';
            $this->helmHistory[$argv[2]][] = ['revision' => count($this->helmHistory[$argv[2]] ?? []) + 1, 'updated' => now()->toRfc3339String(), 'status' => 'deployed', 'chart' => $this->helmCharts[$argv[2]]];

            return new HelmResult(true, 'Rollback was a success! Happy Helming!', '', 0);
        }

        return match ($argv[1] ?? '') {
            'history' => isset($this->helmHistory[$argv[2]])
                ? new HelmResult(true, json_encode($this->helmHistory[$argv[2]]), '', 0)
                : new HelmResult(false, '', 'Error: release: not found', 1),
            'list' => new HelmResult(true, json_encode($this->helmListRows(trim((string) $flag('--filter'), '^$'), (string) $flag('-n'))), '', 0),
            'status' => new HelmResult(true, json_encode(self::HELM_316_STATUS), '', 0),
            'upgrade' => $this->helmUpgradeFails
                ? new HelmResult(false, '', 'Error: UPGRADE FAILED: timed out waiting for the condition', 1)
                : new HelmResult(true, "Release \"{$argv[2]}\" has been upgraded. Happy Helming!", '', 0),
            default => new HelmResult(true, '', '', 0),   // repo add / repo update
        };
    }

    /** `helm list -n <ns> --filter ^<release>$ -o json` of helm 3.16 */
    private function helmListRows(string $release, string $namespace): array
    {
        $chart = $this->helmCharts[$release] ?? '';

        return $chart === '' ? [] : [[
            'name' => $release,
            'namespace' => $namespace,
            'revision' => '2',
            'updated' => '2026-09-30 08:01:44.118904203 +0000 UTC',
            'status' => 'deployed',
            'chart' => $chart,
            'app_version' => $release === 'traefik' ? 'v3.7.4' : 'v2.9.3',
        ]];
    }

    /** @return list<list<string>> argv of the helm commands whose subcommand is $sub */
    private function helm(string $sub): array
    {
        return array_values(array_map(
            fn ($c) => $c['argv'],
            array_filter($this->helmCommands, fn ($c) => ($c['argv'][1] ?? '') === $sub),
        ));
    }

    /**
     * The upgrade commands as [release, chart, namespace, kubeconfig, version, set-json map].
     *
     * @return list<array>
     */
    private function upgrades(): array
    {
        $upgrades = [];
        foreach ($this->helmCommands as $c) {
            $argv = $c['argv'];
            if (($argv[1] ?? '') !== 'upgrade') {
                continue;
            }
            $set = [];
            foreach ($argv as $i => $arg) {
                if ($arg === '--set-json') {
                    [$key, $value] = explode('=', $argv[$i + 1], 2);
                    $set[$key] = $value;
                }
            }
            $at = fn (string $flag) => $argv[array_search($flag, $argv, true) + 1] ?? null;
            $upgrades[] = [$argv[2], $argv[3], $at('-n'), $c['kubeconfig'], in_array('--version', $argv, true) ? $at('--version') : null, $set];
        }

        return $upgrades;
    }

    private function region(string $name, array $attributes = [], array $settings = []): KnativeRegion
    {
        $region = KnativeRegion::create([
            'name' => $name,
            'project_namespace' => $name,
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
        $region->forceFill(array_merge(['knative_settings' => $settings ?: null], $attributes))->save();

        return $region->fresh();
    }

    private function reconcile(array $options = []): string
    {
        Artisan::call('region:reconcile', $options);

        return Artisan::output();
    }

    public function test_dry_run_reports_both_upgrades_and_changes_nothing(): void
    {
        $this->fakeCluster();
        $this->fakeHelm();
        $this->region('fn');

        $output = $this->reconcile(['--dry-run' => true]);

        $this->assertStringContainsString(
            '[ingress-trust] would upgrade Helm release knative-serving/traefik (chart traefik-40.3.0, values reused): '
            .'PROXY protocol / X-Forwarded-* trusted from 0.0.0.0/0 → nobody on web, websecure — the Traefik pods roll once',
            $output,
        );
        $this->assertStringContainsString(
            '[loki-deletion] would upgrade Helm release observability/loki-stack (chart loki-stack-2.10.3, values reused): enable the delete API for log retention — '
            .'compactor.retention_enabled, compactor.deletion_mode, limits_config.allow_deletes, limits_config.retention_period '
            .'(Loki retention 876000h, i.e. none of its own) — the Loki pod restarts once',
            $output,
        );
        $this->assertSame([], $this->upgrades());
        // the deployed chart versions come from helm list — read-only — never from helm status
        $this->assertCount(2, $this->helm('list'));
        $this->assertSame([], $this->helm('status'));
    }

    public function test_reconcile_upgrades_traefik_and_loki_with_only_their_keys(): void
    {
        $this->fakeCluster();
        $this->fakeHelm();
        $region = $this->region('fn');

        $output = $this->reconcile();

        $this->assertStringContainsString('[ingress-trust] upgraded Helm release knative-serving/traefik', $output);
        $this->assertStringContainsString('[loki-deletion] upgraded Helm release observability/loki-stack', $output);
        $this->assertCount(2, $this->upgrades());

        [$release, $chart, $namespace, $kubeconfig, $version, $set] = $this->upgrades()[0];
        $this->assertSame(['traefik', 'traefik/traefik', 'knative-serving', '40.3.0'], [$release, $chart, $namespace, $version]);
        $this->assertSame($region->kubeconfig, $kubeconfig);
        $this->assertSame('[]', $set['ports.web.proxyProtocol.trustedIPs']);
        $this->assertSame('[]', $set['ports.web.forwardedHeaders.trustedIPs']);
        $this->assertSame('[]', $set['ports.websecure.proxyProtocol.trustedIPs']);
        $this->assertSame('[]', $set['ports.websecure.forwardedHeaders.trustedIPs']);
        $this->assertSame('false', $set['ports.web.forwardedHeaders.insecure']);

        [$release, $chart, $namespace, , $version, $set] = $this->upgrades()[1];
        $this->assertSame(['loki-stack', 'grafana/loki-stack', 'observability', '2.10.3'], [$release, $chart, $namespace, $version]);
        $this->assertSame(ManagedLoki::helmSetJson(), $set);

        // the command line: pinned, values reused, no --wait (the Loki rollout goes on in the cluster)
        $loki = $this->helm('upgrade')[1];
        $this->assertSame(['helm', 'upgrade', 'loki-stack', 'grafana/loki-stack', '-n', 'observability'], array_slice($loki, 0, 6));
        $this->assertSame(['--reuse-values', '--version', '2.10.3'], array_slice($loki, 8, 3));
        $this->assertContains('loki.config.compactor.retention_enabled=true', $loki);
        $this->assertContains('loki.config.limits_config.retention_period="876000h"', $loki);
        $this->assertNotContains('--wait', $loki);
        $this->assertContains(['helm', 'list', '-n', 'observability', '--kubeconfig', $this->helm('list')[1][5], '--filter', '^loki-stack$', '-o', 'json'], $this->helm('list'));
    }

    /** traefik's history: revision 2 deployed, revision 3 in $status since $minutesAgo */
    private function pendingTraefik(string $status, int $minutesAgo): void
    {
        $this->helmCharts['traefik'] = '';   // helm list hides a release whose newest revision is pending
        $this->helmPendingChart['traefik'] = 'traefik-40.3.0';
        $this->helmHistory['traefik'] = [
            ['revision' => 1, 'updated' => '2026-09-02T09:14:03.402156871Z', 'status' => 'superseded', 'chart' => 'traefik-40.3.0', 'app_version' => 'v3.7.4', 'description' => 'Install complete'],
            ['revision' => 2, 'updated' => '2026-09-30T08:01:44.118904203Z', 'status' => 'deployed', 'chart' => 'traefik-40.3.0', 'app_version' => 'v3.7.4', 'description' => 'Upgrade complete'],
            ['revision' => 3, 'updated' => now()->subMinutes($minutesAgo)->format('Y-m-d\TH:i:s.uP'), 'status' => $status, 'chart' => 'traefik-40.3.0', 'app_version' => 'v3.7.4', 'description' => 'Preparing upgrade'],
        ];
        if ($status === 'pending-install') {
            $this->helmHistory['traefik'] = [array_merge($this->helmHistory['traefik'][2], ['revision' => 1])];
        }
    }

    public function test_an_upgrade_a_killed_helm_left_pending_is_rolled_back_and_done_again(): void
    {
        // a region:reconcile cut off in `helm upgrade --wait` (the bootstrap Job's time limit
        // before 0.6.0): the release stays pending-upgrade, helm list no longer shows it
        $this->pendingTraefik('pending-upgrade', 20);
        $this->fakeCluster();
        $this->fakeHelm();
        $this->region('fn');

        $dry = $this->reconcile(['--dry-run' => true]);
        $this->assertStringContainsString('[ingress-trust] would roll back the interrupted pending-upgrade of Helm release knative-serving/traefik once it is 13 minutes old, then upgrade it', $dry);
        $this->assertSame([], $this->helm('rollback'));

        Artisan::call('region:reconcile');
        $output = Artisan::output();

        $this->assertStringContainsString('[ingress-trust] rolled back the interrupted pending-upgrade of Helm release knative-serving/traefik since ', $output);
        $this->assertStringContainsString(' to revision 2; upgraded Helm release knative-serving/traefik (chart traefik-40.3.0, values reused)', $output);
        $this->assertSame(['helm', 'rollback', 'traefik', '2', '-n', 'knative-serving'], array_slice($this->helm('rollback')[0], 0, 6));
        $this->assertSame('traefik', $this->upgrades()[0][0]);
        $this->assertStringNotContainsString('[ingress-trust] failed', $output);
    }

    public function test_a_pending_operation_that_may_still_run_fails_the_step(): void
    {
        $this->pendingTraefik('pending-upgrade', 2);
        $this->fakeCluster();
        $this->fakeHelm();
        $this->region('fn');

        $exit = Artisan::call('region:reconcile');
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'not "left alone": the step failed, the bootstrap Job fails');
        $this->assertStringContainsString('[ingress-trust] failed: Helm release knative-serving/traefik is pending-upgrade since ', $output);
        $this->assertStringContainsString('another helm operation may still run on it — it is rolled back once it is 13 minutes old', $output);
        $this->assertSame([], $this->helm('rollback'));
        $this->assertSame([], array_filter($this->upgrades(), fn ($u) => $u[0] === 'traefik'));
    }

    public function test_an_interrupted_first_install_is_left_to_the_operator(): void
    {
        $this->pendingTraefik('pending-install', 30);
        $this->fakeCluster();
        $this->fakeHelm();
        $this->region('fn');

        $this->assertSame(1, Artisan::call('region:reconcile'));
        $output = Artisan::output();
        $this->assertStringContainsString('is pending-install since', $output);
        $this->assertStringContainsString('check it with helm -n knative-serving history traefik and roll it back or uninstall it by hand', $output);
        $this->assertSame([], $this->helm('rollback'));
    }

    public function test_an_install_that_meets_an_interrupted_operation_rolls_it_back_and_runs_again(): void
    {
        // HelmService::install (upgrade --install: tracing, cert-manager): "another operation is in progress"
        $this->pendingTraefik('pending-upgrade', 20);
        $attempts = 0;
        $runner = function (array $argv) use (&$attempts) {
            if (($argv[1] ?? '') === 'upgrade' && in_array('--install', $argv, true)) {
                $attempts++;

                return $attempts === 1
                    ? new HelmResult(false, '', 'Error: UPGRADE FAILED: another operation (install/upgrade/rollback) is in progress', 1)
                    : new HelmResult(true, 'Release "traefik" has been upgraded.', '', 0);
            }

            return $this->helmRun($argv);
        };
        $helm = new class($runner) extends HelmService
        {
            public function __construct(private \Closure $runner) {}

            protected function run(array $command): HelmResult
            {
                return ($this->runner)($command);
            }
        };

        $result = $helm->install('traefik', 'traefik/traefik', 'knative-serving', [], 'kubeconfig', '40.3.0', wait: false);

        $this->assertTrue($result->success);
        $this->assertSame(2, $attempts);
        $this->assertCount(1, $this->helm('rollback'));
    }

    public function test_without_a_deployed_chart_version_nothing_is_upgraded(): void
    {
        // helm status -o json (helm 3.16) has no chart at all, and helm list does not show the
        // release (e.g. a pending upgrade): no unpinned upgrade to the repo's latest chart
        $this->helmCharts = ['traefik' => '', 'loki-stack' => ''];
        $this->fakeCluster();
        $this->fakeHelm();
        $region = $this->region('fn');

        $this->assertArrayNotHasKey('chart', self::HELM_316_STATUS);
        $this->assertNull(app(HelmService::class)->deployedChartVersion('loki-stack', 'observability', $region->kubeconfig, 'loki-stack'));
        $this->helmCommands = [];

        $output = $this->reconcile();

        $this->assertStringContainsString(
            '[ingress-trust] left alone: cannot determine deployed chart version of Helm release knative-serving/traefik '
            .'(helm list -n knative-serving shows no deployed traefik release) — PROXY protocol / X-Forwarded-* trusted from 0.0.0.0/0 → nobody on web, websecure not applied',
            $output,
        );
        $this->assertStringContainsString(
            '[loki-deletion] left alone: cannot determine deployed chart version of Helm release observability/loki-stack '
            .'(helm list -n observability shows no deployed loki-stack release) — enable the delete API for log retention',
            $output,
        );
        $this->assertSame([], $this->helm('upgrade'));
        $this->assertSame([], $this->helm('repo'));
    }

    public function test_up_to_date_installs_are_left_alone(): void
    {
        $this->cluster['traefikArgs'] = ['--entryPoints.web.address=:8000/tcp', '--providers.knative', '--entryPoints.web.transport.respondingTimeouts.readTimeout=310s', '--entryPoints.web.transport.respondingTimeouts.writeTimeout=310s', '--entryPoints.websecure.transport.respondingTimeouts.readTimeout=310s', '--entryPoints.websecure.transport.respondingTimeouts.writeTimeout=310s'];
        $this->cluster['lokiConfig'] = array_replace_recursive($this->cluster['lokiConfig'], ManagedLoki::deletionConfig());
        $this->fakeCluster();
        $this->fakeHelm();
        $this->region('fn');

        $output = $this->reconcile();

        $this->assertStringContainsString('[ingress-trust] up to date', $output);
        $this->assertStringContainsString('[loki-deletion] up to date', $output);
        $this->assertSame([], $this->upgrades());
    }

    public function test_sibling_regions_share_the_traefik_and_their_trusted_ips(): void
    {
        $this->fakeCluster();
        $this->fakeHelm();
        $this->region('fn-a', [], ['ingress_trusted_ips' => ['192.0.2.7']]);
        $this->region('fn-b', [], ['ingress_trusted_ips' => ['10.20.0.0/24', '192.0.2.7']]);

        $output = $this->reconcile();

        $this->assertStringContainsString('trusted from 0.0.0.0/0 → 10.20.0.0/24, 192.0.2.7 on web, websecure', $output);
        $traefik = $this->upgrades()[0][5];
        $this->assertSame('["10.20.0.0/24","192.0.2.7"]', $traefik['ports.web.proxyProtocol.trustedIPs']);
        $this->assertSame('["10.20.0.0/24","192.0.2.7"]', $traefik['ports.websecure.forwardedHeaders.trustedIPs']);
        $this->assertCount(1, array_filter($this->upgrades(), fn ($u) => $u[0] === 'traefik'), 'one Traefik per cluster');
    }

    public function test_a_traefik_or_loki_runr_did_not_install_is_never_touched(): void
    {
        $this->cluster['traefikRelease'] = 'platform-ingress';
        $this->fakeCluster();
        $this->fakeHelm();
        // Loki reused from the platform
        $this->region('fn', ['loki_managed' => false, 'loki_url' => 'http://platform-loki.logging.svc:3100']);

        $output = $this->reconcile();

        $this->assertStringContainsString("[ingress-trust] left alone: Traefik knative-serving/traefik is not RunR's Helm release", $output);
        $this->assertStringContainsString('[loki-deletion] up to date', $output);
        $this->assertSame([], $this->upgrades());
        $this->assertNotContains('GET /api/v1/namespaces/observability/secrets/loki-stack', $this->calls);
    }

    public function test_a_failed_helm_upgrade_is_reported_as_a_step_failure(): void
    {
        $this->helmUpgradeFails = true;
        $this->fakeCluster();
        $this->fakeHelm();
        $this->region('fn');

        $exit = Artisan::call('region:reconcile');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[ingress-trust] failed: helm upgrade of knative-serving/traefik failed: Error: UPGRADE FAILED: timed out', $output);
        $this->assertStringContainsString('[loki-deletion] failed: helm upgrade of observability/loki-stack failed', $output);
    }

    // ── Region setting ingress_trusted_ips ───────────────────────────────────

    public function test_the_setting_is_validated_stored_and_applied(): void
    {
        Bus::fake([ApplyIngressTrustJob::class]);
        $this->fakeCluster();
        $user = User::factory()->create(['username' => 'admin']);
        $region = $this->region('fn');
        $put = fn (array $body) => $this->actingAs($user, 'sanctum')->putJson("/api/knative-regions/{$region->id}/settings", $body);

        $put(['ingress_trusted_ips' => ['lb.example.test']])->assertStatus(422)->assertJsonValidationErrors('ingress_trusted_ips.0');
        $put(['ingress_trusted_ips' => ['10.0.0.0/33']])->assertStatus(422);
        $put(['ingress_trusted_ips' => '10.0.0.0/24'])->assertStatus(422)->assertJsonValidationErrors('ingress_trusted_ips');
        Bus::assertNotDispatched(ApplyIngressTrustJob::class);

        $put(['ingress_trusted_ips' => ['192.0.2.7', ' 10.20.0.0/24']])
            ->assertOk()
            ->assertJsonPath('settings.ingress_trusted_ips', ['10.20.0.0/24', '192.0.2.7']);
        $this->assertSame(['10.20.0.0/24', '192.0.2.7'], $region->fresh()->knative_settings['ingress_trusted_ips']);
        Bus::assertDispatchedTimes(ApplyIngressTrustJob::class, 1);

        // other settings leave the Traefik alone
        $put(['request_timeout' => 120])->assertOk();
        Bus::assertDispatchedTimes(ApplyIngressTrustJob::class, 1);

        // cleared: trust nobody again
        $put(['ingress_trusted_ips' => null])->assertOk()->assertJsonPath('settings.ingress_trusted_ips', []);
        Bus::assertDispatchedTimes(ApplyIngressTrustJob::class, 2);
    }

    public function test_the_apply_job_brings_the_cluster_traefik_to_the_setting(): void
    {
        $this->fakeCluster();
        $this->fakeHelm();
        $region = $this->region('fn-a', [], ['ingress_trusted_ips' => ['10.20.0.0/24']]);
        $this->region('fn-b', [], ['ingress_trusted_ips' => ['192.0.2.7']]);   // same cluster (kube-system uid)

        (new ApplyIngressTrustJob($region))->handle(app(RunrClusterIdentity::class));

        $this->assertCount(1, $this->upgrades());
        $this->assertSame('traefik', $this->upgrades()[0][0]);
        $this->assertSame('["10.20.0.0/24","192.0.2.7"]', $this->upgrades()[0][5]['ports.web.proxyProtocol.trustedIPs']);
    }
}
