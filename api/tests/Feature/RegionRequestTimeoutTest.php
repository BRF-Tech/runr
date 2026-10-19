<?php

namespace Tests\Feature;

use App\Jobs\ApplyIngressTrustJob;
use App\Jobs\ApplyRequestTimeoutJob;
use App\Jobs\RedeployRegionAgentJob;
use App\Models\KnativeRegion;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\Helpers\RequestTimeout;
use App\Services\KnativeInstallService;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\RegionReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * request_timeout reaches what serves the requests: the region Traefik's read / write timeouts
 * (installed at 120 s, so a function was cut after 120 s whatever its timeout) follow the longest
 * setting of the regions on the cluster plus a margin, and Knative's max-revision-timeout-seconds
 * (600 s by default) is raised to it. A settings change applies it right away; region:reconcile
 * brings existing regions there. A changed agent_ws_url deploys the agent again; the unused
 * `namespace` setting is gone.
 */
class RegionRequestTimeoutTest extends TestCase
{
    use ActsWithRoles, BuildsAiFixtures, RefreshDatabase;

    private const CR = '/apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving';

    /** @var list<string> Traefik container args */
    private array $traefikArgs = [];

    private array $crDefaults = [];

    /** @var list<list<string>> argv of every helm command */
    private array $helmCommands = [];

    /** RUNR_REGION_ID of the cluster's runr-agent */
    private string $agentOwner = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);
        config(['observability.tracing.install_with_serving' => false]);
        RegionReconciler::clearRegionSources();

        $this->traefikArgs = [
            '--entryPoints.web.transport.respondingTimeouts.readTimeout=120s',
            '--entryPoints.web.transport.respondingTimeouts.writeTimeout=120s',
            '--entryPoints.web.transport.respondingTimeouts.idleTimeout=300s',
            '--entryPoints.websecure.transport.respondingTimeouts.readTimeout=120s',
            '--entryPoints.websecure.transport.respondingTimeouts.writeTimeout=120s',
        ];

        $this->app->instance(HelmService::class, new class(fn (array $argv) => $this->helmRun($argv)) extends HelmService
        {
            public function __construct(private \Closure $runner) {}

            protected function run(array $command): HelmResult
            {
                return ($this->runner)($command);
            }
        });
    }

    protected function tearDown(): void
    {
        RegionReconciler::clearRegionSources();
        parent::tearDown();
    }

    private function helmRun(array $argv): HelmResult
    {
        $this->helmCommands[] = $argv;

        return match ($argv[1] ?? '') {
            'list' => new HelmResult(true, json_encode([['name' => 'traefik', 'namespace' => 'knative-serving', 'status' => 'deployed', 'chart' => 'traefik-40.3.0']]), '', 0),
            default => new HelmResult(true, '', '', 0),
        };
    }

    private function cluster(): void
    {
        $this->fakeCluster([
            'GET /version' => ['major' => '1', 'minor' => '32'],
            'GET /api/v1/namespaces/kube-system' => ['metadata' => ['uid' => 'uid-1']],
            'GET /apis/apps/v1/namespaces/knative-serving/deployments/traefik' => fn () => [
                'metadata' => ['name' => 'traefik', 'annotations' => ['meta.helm.sh/release-name' => 'traefik', 'meta.helm.sh/release-namespace' => 'knative-serving']],
                'spec' => ['template' => ['spec' => ['containers' => [['name' => 'traefik', 'args' => $this->traefikArgs]]]]],
            ],
            'GET '.self::CR => fn () => ['spec' => ['config' => ['defaults' => $this->crDefaults]]],
        ]);
    }

    private function region(string $name, int $timeout): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => $name, 'project_namespace' => $name, 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_serving_status' => 'ready', 'knative_settings' => ['request_timeout' => $timeout],
        ]);
    }

    /** @return array<string, string> --set-json pairs of the helm upgrades */
    private function upgradeSets(): array
    {
        $set = [];
        foreach ($this->helmCommands as $argv) {
            if (($argv[1] ?? '') !== 'upgrade') {
                continue;
            }
            foreach ($argv as $i => $arg) {
                if ($arg === '--set-json') {
                    [$key, $value] = explode('=', $argv[$i + 1], 2);
                    $set[$key] = $value;
                }
            }
        }

        return $set;
    }

    public function test_reconcile_follows_the_longest_setting_of_the_cluster(): void
    {
        $this->cluster();
        $this->region('eu-1', 300);
        $this->region('eu-2', 1800);

        Artisan::call('region:reconcile');
        $output = Artisan::output();

        $this->assertSame([
            'ports.web.transport.respondingTimeouts.readTimeout' => '"1810s"',
            'ports.web.transport.respondingTimeouts.writeTimeout' => '"1810s"',
            'ports.websecure.transport.respondingTimeouts.readTimeout' => '"1810s"',
            'ports.websecure.transport.respondingTimeouts.writeTimeout' => '"1810s"',
        ], $this->upgradeSets());
        $patch = $this->k8sCallsTo('PATCH', self::CR);
        $this->assertSame('1800', end($patch)['body']['spec']['config']['defaults']['max-revision-timeout-seconds']);
        $this->assertStringContainsString('read / write timeout of web, websecure 120 s → 1810 s (longest request_timeout 1800 s)', $output);
    }

    public function test_up_to_date_timeouts_change_nothing_and_knative_is_never_lowered(): void
    {
        $this->cluster();
        $this->traefikArgs = [
            '--entryPoints.web.transport.respondingTimeouts.readTimeout=310s',
            '--entryPoints.web.transport.respondingTimeouts.writeTimeout=310s',
            '--entryPoints.websecure.transport.respondingTimeouts.readTimeout=310s',
            '--entryPoints.websecure.transport.respondingTimeouts.writeTimeout=310s',
        ];
        $this->crDefaults = ['max-revision-timeout-seconds' => '3600'];
        $this->region('eu-1', 300);

        Artisan::call('region:reconcile');

        $this->assertSame([], $this->upgradeSets());
        $this->assertSame([], array_filter(
            $this->k8sCallsTo('PATCH', self::CR),
            fn ($call) => isset($call['body']['spec']['config']['defaults']),
        ));
    }

    public function test_a_settings_change_applies_the_timeout_and_redeploys_the_agent(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        $region = $this->region('eu-1', 300);
        Bus::fake([ApplyRequestTimeoutJob::class, RedeployRegionAgentJob::class, ApplyIngressTrustJob::class]);

        $this->putJson("/api/knative-regions/{$region->id}/settings", ['request_timeout' => 900, 'namespace' => 'other'])->assertOk();
        Bus::assertDispatched(ApplyRequestTimeoutJob::class);
        Bus::assertNotDispatched(RedeployRegionAgentJob::class);
        $this->assertArrayNotHasKey('namespace', $region->fresh()->knative_settings, 'never read: not stored');

        $this->putJson("/api/knative-regions/{$region->id}/settings", ['agent_ws_url' => 'wss://runr.internal.example.test'])->assertOk();
        Bus::assertDispatched(RedeployRegionAgentJob::class);
        Bus::assertDispatchedTimes(ApplyRequestTimeoutJob::class, 1);

        $this->putJson("/api/knative-regions/{$region->id}/settings", ['request_timeout' => 900, 'agent_ws_url' => 'wss://runr.internal.example.test'])->assertOk();
        Bus::assertDispatchedTimes(ApplyRequestTimeoutJob::class, 1);
        Bus::assertDispatchedTimes(RedeployRegionAgentJob::class, 1);
    }

    public function test_the_timeout_job_runs_the_step_for_the_region(): void
    {
        $this->cluster();
        $region = $this->region('eu-1', 600);

        dispatch_sync(new ApplyRequestTimeoutJob($region));

        $this->assertSame('"610s"', $this->upgradeSets()['ports.web.transport.respondingTimeouts.writeTimeout']);
        $this->assertSame([], $this->k8sCallsTo('PATCH', self::CR), 'Knative allows 600 s already');
    }

    public function test_the_agent_job_deploys_this_regions_agent_and_leaves_another_regions_alone(): void
    {
        $region = $this->region('eu-1', 300);
        $this->agentOwner = 'another-region';
        $this->fakeCluster([
            'GET /apis/apps/v1/namespaces/observability/deployments/runr-agent' => fn () => [
                'spec' => ['template' => ['spec' => ['containers' => [['env' => [['name' => 'RUNR_REGION_ID', 'value' => $this->agentOwner]]]]]]],
            ],
        ]);

        dispatch_sync(new RedeployRegionAgentJob($region));
        $this->assertSame([], $this->k8sCallsTo('PATCH', '/deployments/runr-agent'), 'the agent of another region');

        $this->agentOwner = $region->id;
        dispatch_sync(new RedeployRegionAgentJob($region));
        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/apis/apps/v1/namespaces/observability/deployments/runr-agent'));
    }

    public function test_a_new_traefik_and_knative_serving_take_the_setting(): void
    {
        $service = (new \ReflectionClass(KnativeInstallService::class))->newInstanceWithoutConstructor();
        $values = (new \ReflectionMethod($service, 'traefikHelmValues'))->invoke($service, 'knative-serving', [], 1200);
        $this->assertSame(['readTimeout' => '1210s', 'writeTimeout' => '1210s', 'idleTimeout' => '300s'], $values['ports']['websecure']['transport']['respondingTimeouts']);

        $this->assertSame(120, RequestTimeout::traefikSeconds(30), 'never below the earlier 120 s');
        $this->assertSame(600, RequestTimeout::knativeMax(300));

        $this->fakeCluster();
        $apply = new \ReflectionMethod($service, 'applyKnativeServingCR');
        $k8s = new KubernetesClient($this->fakeKubeconfig());

        $apply->invoke($service, $k8s, ['request_timeout' => 1800], 'knative-serving', '1.20');
        $apply->invoke($service, $k8s, ['request_timeout' => 300], 'knative-serving', '1.20');

        [$long, $default] = $this->k8sCallsTo('PATCH', self::CR);
        $this->assertSame(['max-revision-timeout-seconds' => '1800'], $long['body']['spec']['config']['defaults']);
        $this->assertArrayNotHasKey('defaults', $default['body']['spec']['config'], 'Knative\'s own 600 s allow it');
    }
}
