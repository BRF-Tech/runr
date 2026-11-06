<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\Manifests\ZotManifests;
use App\Services\ZotRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * registry:upgrade-zot moves one region's internal registry to the pinned Zot chart. region:reconcile
 * never does (it would restart the registries of every region unasked), so this is the way an
 * installed Zot gets a new version: with the values of its release, the config.json that runs now
 * brought to what the new version needs, and never to an older chart.
 */
class RegistryUpgradeZotCommandTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    private const NS = 'runr-registry';

    private const STS = '/apis/apps/v1/namespaces/runr-registry/statefulsets/runr-zot';

    private const DEPLOYMENT = '/apis/apps/v1/namespaces/runr-registry/deployments/runr-zot';

    private const CONFIG_MAP = '/api/v1/namespaces/runr-registry/configmaps/runr-zot-config';

    /** The config.json region:reconcile left, which the install values do not have */
    private const LIVE_CONFIG = '{"storage":{"rootDirectory":"/var/lib/registry"},"extensions":{"sync":{"registries":[{"urls":["https://mirror.example"]}]}}}';

    /** @var list<array{release: string, chart: string, namespace: string, version: string, values: array}> */
    private array $upgrades = [];

    private ?string $deployed = '0.1.125';

    private bool $helmFails = false;

    /** The newest revision's status; `superseded`: nothing pending */
    private string $pendingStatus = 'superseded';

    /** The next upgrade finds an operation in progress */
    private bool $upgradeBlocked = false;

    private int $recoveries = 0;

    /** recoverInterrupted() refuses: the pending revision is too young */
    private ?string $recoveryRefused = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(HelmService::class, new class($this) extends HelmService
        {
            public function __construct(private readonly RegistryUpgradeZotCommandTest $test) {}

            public function repoAdd(string $name, string $url): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }

            public function repoUpdate(): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }

            public function deployedChartVersion(string $release, string $namespace, string $kubeconfigYaml, string $chartName): ?string
            {
                return $this->test->deployedChart();
            }

            public function upgradeToChart(string $release, string $chart, string $namespace, string $kubeconfigYaml, string $version, array $valuesArray = []): HelmResult
            {
                return $this->test->recordUpgrade($release, $chart, $namespace, $version, $valuesArray);
            }

            public function history(string $release, string $namespace, string $kubeconfigYaml, int $max = 10): ?array
            {
                return $this->test->releaseHistory();
            }

            public function recoverInterrupted(string $release, string $namespace, string $kubeconfigYaml): ?string
            {
                return $this->test->recover();
            }
        });
    }

    public function deployedChart(): ?string
    {
        return $this->deployed;
    }

    public function recordUpgrade(string $release, string $chart, string $namespace, string $version, array $values): HelmResult
    {
        $this->upgrades[] = compact('release', 'chart', 'namespace', 'version', 'values');

        if ($this->upgradeBlocked) {
            $this->upgradeBlocked = false;

            return new HelmResult(false, '', "Error: UPGRADE FAILED: another operation (install/upgrade/rollback) is in progress\n", 1);
        }

        return $this->helmFails ? new HelmResult(false, '', "timed out waiting for the condition\n", 1) : new HelmResult(true, '', '', 0);
    }

    /** @return list<array{revision: int, status: string, updated: string}> */
    public function releaseHistory(): array
    {
        return [
            ['revision' => 3, 'status' => 'deployed', 'updated' => '2026-11-01T10:00:00Z'],
            ['revision' => 4, 'status' => $this->pendingStatus, 'updated' => '2026-11-05T10:00:00Z'],
        ];
    }

    /** What HelmService::recoverInterrupted() does with the release: rolled back, too young, or nothing pending. */
    public function recover(): ?string
    {
        $this->recoveries++;
        if ($this->recoveryRefused !== null) {
            throw new \RuntimeException($this->recoveryRefused);
        }
        if ($this->pendingStatus === 'superseded') {
            return null;
        }
        $this->pendingStatus = 'superseded';
        $this->deployed = '0.1.125';

        return 'rolled back the interrupted pending-upgrade of Helm release runr-registry/runr-zot to revision 3';
    }

    private function cluster(string $workload = self::STS, string $config = self::LIVE_CONFIG): void
    {
        $this->fakeCluster([
            "GET {$workload}" => [
                'spec' => ['template' => ['spec' => ['containers' => [['name' => 'zot', 'image' => 'ghcr.io/project-zot/zot:v2.1.21']]]]],
                'status' => ['readyReplicas' => 1],
            ],
            'GET '.self::CONFIG_MAP => ['data' => ['config.json' => $config]],
        ]);
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'registry_mode' => 'internal', 'registry_status' => 'ready', 'registry_address' => '10.0.0.5:5000',
            'registry_namespace' => self::NS, 'registry_storage_type' => 'pvc',
            'registry_storage_config' => ['storageClass' => 'local-path', 'size' => '50Gi'],
            // selected after the install: the upgrade must not move Zot's node-bound volume to them
            'selected_workers' => ['worker-1'],
        ], $attributes));
    }

    public function test_the_registry_moves_to_the_pinned_chart_with_the_config_that_runs(): void
    {
        $this->cluster();
        $region = $this->region();

        $this->artisan('registry:upgrade-zot', ['regionId' => $region->id])
            ->expectsOutputToContain('upgraded Helm release runr-registry/runr-zot (chart zot-0.1.125 → zot-'.ZotRegistryService::ZOT_CHART_VERSION)
            ->assertExitCode(0);

        $this->assertCount(1, $this->upgrades);
        $upgrade = $this->upgrades[0];
        $this->assertSame(['runr-zot', 'zot/zot', self::NS, ZotRegistryService::ZOT_CHART_VERSION], [$upgrade['release'], $upgrade['chart'], $upgrade['namespace'], $upgrade['version']]);
        $this->assertSame(['config.json' => ZotManifests::reconcileSyncConfig(self::LIVE_CONFIG, true)], $upgrade['values']['configFiles']);
        $this->assertSame(['fsGroup' => 1000], $upgrade['values']['podSecurityContext'], 'the chart keeps the fsGroup: no second restart');
        $this->assertArrayNotHasKey('nodeSelector', $upgrade['values'], 'placement stays the release\'s');
        $this->assertArrayNotHasKey('pvc', $upgrade['values'], 'the volume stays the release\'s');

        $patch = $this->k8sCallsTo('PATCH', self::STS);
        $this->assertSame('fix-permissions', $patch[0]['body']['spec']['template']['spec']['initContainers'][0]['name'], 'a Zot on a PVC keeps its init container');
    }

    public function test_a_registry_on_s3_needs_no_volume_permissions(): void
    {
        $this->cluster(self::DEPLOYMENT);
        $region = $this->region(['registry_storage_type' => 's3']);

        $this->artisan('registry:upgrade-zot', ['regionId' => $region->id])->assertExitCode(0);

        $this->assertSame(['configFiles' => ['config.json' => ZotManifests::reconcileSyncConfig(self::LIVE_CONFIG, true)]], $this->upgrades[0]['values']);
        $this->assertSame([], $this->k8sCallsTo('PATCH', self::DEPLOYMENT));
    }

    /**
     * region:reconcile leaves a Zot of an earlier release alone, so the new version comes with what
     * it needs here: with the old config a build could not push (503) and Docker-format images
     * would not sync. What the config already holds stays.
     */
    public function test_the_new_zot_gets_the_sync_config_it_needs_with_the_operators_entries(): void
    {
        $this->cluster();
        $region = $this->region();

        $this->artisan('registry:upgrade-zot', ['regionId' => $region->id])
            ->expectsOutputToContain("config.json takes this release's sync upstreams and http.compat")
            ->assertExitCode(0);

        $config = json_decode($this->upgrades[0]['values']['configFiles']['config.json'], true);
        $this->assertSame(['docker2s2'], $config['http']['compat']);
        $this->assertSame(['rootDirectory' => '/var/lib/registry'], $config['storage']);
        $this->assertContains(['urls' => ['https://mirror.example']], $config['extensions']['sync']['registries'], 'an operator\'s upstream stays');
        $this->assertContains(ZotManifests::syncRegistries(true)[1], $config['extensions']['sync']['registries'], 'Docker Hub without RunR\'s own namespaces');
    }

    public function test_a_config_that_needs_nothing_goes_as_it_is(): void
    {
        $current = json_encode(ZotManifests::mirrorConfig());
        $this->cluster(self::STS, $current);
        $region = $this->region();

        $this->artisan('registry:upgrade-zot', ['regionId' => $region->id])
            ->doesntExpectOutputToContain('config.json takes')
            ->assertExitCode(0);

        $this->assertSame(['config.json' => $current], $this->upgrades[0]['values']['configFiles']);
    }

    public function test_a_config_that_is_no_json_stops_the_upgrade(): void
    {
        $this->cluster(self::STS, '{"storage":');
        $region = $this->region();

        $this->artisan('registry:upgrade-zot', ['regionId' => $region->id])
            ->expectsOutputToContain('is not valid JSON')
            ->assertExitCode(1);

        $this->assertSame([], $this->upgrades);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->cluster();
        $region = $this->region();

        $this->artisan('registry:upgrade-zot', ['regionId' => $region->id, '--dry-run' => true])
            ->expectsOutputToContain('would upgrade Helm release runr-registry/runr-zot (chart zot-0.1.125 → zot-'.ZotRegistryService::ZOT_CHART_VERSION.', ghcr.io/project-zot/zot:v2.1.21)')
            ->assertExitCode(0);

        $this->assertSame([], $this->upgrades);
        $this->assertSame([], $this->k8sCallsTo('PATCH', self::STS));
    }

    public function test_a_registry_at_the_pinned_chart_or_past_it_is_left_alone(): void
    {
        $this->cluster();
        $region = $this->region();

        $this->deployed = ZotRegistryService::ZOT_CHART_VERSION;
        $this->artisan('registry:upgrade-zot', ['regionId' => $region->id])->expectsOutputToContain('up to date')->assertExitCode(0);

        $this->deployed = '99.0.0';
        $this->artisan('registry:upgrade-zot', ['regionId' => $region->id])->expectsOutputToContain('up to date')->assertExitCode(0);

        $this->assertSame([], $this->upgrades, 'never a downgrade');
    }

    public function test_only_an_internal_registry_helm_installed_is_upgraded(): void
    {
        $this->cluster();

        $this->artisan('registry:upgrade-zot', ['regionId' => $this->region(['name' => 'ext', 'project_namespace' => 'ext', 'registry_mode' => 'external'])->id])
            ->expectsOutputToContain('has no internal registry')->assertExitCode(1);

        $this->deployed = null;
        $this->artisan('registry:upgrade-zot', ['regionId' => $this->region()->id])
            ->expectsOutputToContain('shows no deployed zot release')->assertExitCode(1);

        $this->artisan('registry:upgrade-zot', ['regionId' => 'no-such-region'])
            ->expectsOutputToContain('Region not found')->assertExitCode(1);

        $this->assertSame([], $this->upgrades);
    }

    public function test_a_failed_upgrade_fails_the_command(): void
    {
        $this->cluster();
        $this->helmFails = true;

        $this->artisan('registry:upgrade-zot', ['regionId' => $this->region()->id])
            ->expectsOutputToContain('Zot Helm upgrade failed: timed out waiting for the condition')
            ->assertExitCode(1);

        $this->assertSame([], $this->k8sCallsTo('PATCH', self::STS), 'nothing is patched on a rolled back release');
    }

    /** A killed upgrade leaves the release pending, and helm list then shows no release at all. */
    public function test_an_interrupted_upgrade_is_rolled_back_and_done_again(): void
    {
        $this->cluster();
        $this->deployed = null;
        $this->pendingStatus = 'pending-upgrade';

        $this->artisan('registry:upgrade-zot', ['regionId' => $this->region()->id])
            ->expectsOutputToContain('rolled back the interrupted pending-upgrade of Helm release runr-registry/runr-zot to revision 3; upgraded Helm release')
            ->assertExitCode(0);

        $this->assertSame(1, $this->recoveries);
        $this->assertCount(1, $this->upgrades);
    }

    /** The release was listed, and helm still refuses: a process that died after helm list. */
    public function test_an_operation_in_progress_is_recovered_and_the_upgrade_tried_once_more(): void
    {
        $this->cluster();
        $this->upgradeBlocked = true;
        $this->pendingStatus = 'pending-upgrade';

        $this->artisan('registry:upgrade-zot', ['regionId' => $this->region()->id])
            ->expectsOutputToContain('rolled back the interrupted pending-upgrade of Helm release runr-registry/runr-zot to revision 3; upgraded Helm release')
            ->assertExitCode(0);

        $this->assertSame(1, $this->recoveries);
        $this->assertCount(2, $this->upgrades);
    }

    public function test_a_pending_release_that_may_still_run_is_left_to_a_later_try(): void
    {
        $this->cluster();
        $this->deployed = null;
        $this->pendingStatus = 'pending-upgrade';
        $this->recoveryRefused = 'Helm release runr-registry/runr-zot is pending-upgrade: another helm operation may still run on it';

        $this->artisan('registry:upgrade-zot', ['regionId' => $this->region()->id])
            ->expectsOutputToContain('another helm operation may still run on it')
            ->assertExitCode(1);

        $this->assertSame([], $this->upgrades);
    }

    public function test_a_dry_run_says_what_it_would_roll_back_and_touches_nothing(): void
    {
        $this->cluster();
        $this->deployed = null;
        $this->pendingStatus = 'pending-upgrade';

        $this->artisan('registry:upgrade-zot', ['regionId' => $this->region()->id, '--dry-run' => true])
            ->expectsOutputToContain('would roll back the interrupted pending-upgrade of Helm release runr-registry/runr-zot once it is 13 minutes old')
            ->assertExitCode(0);

        $this->assertSame(0, $this->recoveries);
        $this->assertSame([], $this->upgrades);
    }

    /** A chart whose values.yaml names the image: --reuse-values would keep running the old one. */
    public function test_the_chart_upgrade_takes_the_new_chart_s_defaults_with_the_release_s_values(): void
    {
        $helm = new class extends HelmService
        {
            /** @var list<string> */
            public array $command = [];

            public string $valuesFile = '';

            public function __construct() {}

            protected function run(array $command): HelmResult
            {
                $this->command = $command;
                $file = array_search('--values', $command, true);
                $this->valuesFile = $file === false ? '' : (string) file_get_contents($command[$file + 1]);

                return new HelmResult(true, '', '', 0);
            }
        };

        $helm->upgradeToChart('runr-zot', 'zot/zot', self::NS, $this->fakeKubeconfig(), '0.1.130', ['configFiles' => ['config.json' => '{}']]);

        $this->assertContains('--reset-then-reuse-values', $helm->command);
        $this->assertNotContains('--reuse-values', $helm->command);
        $this->assertNotContains('--install', $helm->command);
        $this->assertContains('--atomic', $helm->command);
        $this->assertSame('0.1.130', $helm->command[array_search('--version', $helm->command, true) + 1]);
        $this->assertStringContainsString('config.json', $helm->valuesFile, 'in a file, not on the command line');
    }
}
