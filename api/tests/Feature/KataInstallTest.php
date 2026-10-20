<?php

namespace Tests\Feature;

use App\Events\PlatformEvent;
use App\Exceptions\SandboxAddonFailedException;
use App\Exceptions\SandboxInUseException;
use App\Http\Requests\KnativeRegion\UpdateSandboxMappingRequest;
use App\Jobs\InstallSandboxJob;
use App\Services\KubernetesClient;
use App\Services\SandboxInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesKataCluster;
use Tests\TestCase;

/**
 * Kata install / uninstall through RunR (0.4.1):
 *  - ready only once kata-deploy finished on every node (node label + its log line), with a
 *    timeout and a clear failed state; the log says what restarts;
 *  - RuntimeClasses with RunR's handlers, pod overhead and nodeSelector (recreated where the
 *    handler changed);
 *  - Firecracker: devmapper snapshotter set up before kata-deploy's restart, nodes labelled;
 *  - uninstall refused while Kata pods run, kata-deploy's cleanup awaited, devmapper removed.
 */
class KataInstallTest extends TestCase
{
    use FakesKataCluster, RefreshDatabase;

    private const DONE_LOG = "[2026-09-30T17:35:30Z INFO  kata_deploy] Kata Containers installation completed successfully\n"
        ."[2026-09-30T17:35:30Z INFO  kata_deploy] Install completed, daemonset mode: waiting for SIGTERM\n";

    /** @var list<string> install / uninstall log snapshots (install.progress events) */
    private array $progress = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(PlatformEvent::class, function (PlatformEvent $e) {
            $payload = (array) ($e->payload ?? []);
            if (isset($payload['message'])) {
                $this->progress[] = (string) $payload['message'];
            }
        });
    }

    private function service(int $waitSeconds = 3): SandboxInstallService
    {
        $service = app(SandboxInstallService::class);
        $service->kataWaitSeconds = $waitSeconds;
        $service->kataCleanupSeconds = 3;
        $service->kataDevmapperSeconds = 3;
        $service->kataPollSeconds = 1;

        return $service;
    }

    /** kata-deploy "finishes" on every node as soon as the installer DaemonSet is applied. */
    private function kataDeployFinishesOnApply(): void
    {
        $this->onApply['kata-installer'] = function () {
            foreach (array_keys($this->nodes) as $node) {
                $this->labelNode($node, 'katacontainers.io/kata-runtime', 'true');
                $this->podLogs[$this->podName('kata-installer', $node)] = self::DONE_LOG;
            }
        };
    }

    private function lastProgress(): string
    {
        return (string) end($this->progress);
    }

    public function test_not_ready_while_kata_deploy_has_not_labelled_the_node(): void
    {
        $this->addNode('n1');
        $this->fakeKataCluster();
        $region = $this->kataRegion();

        try {
            $this->service(2)->installKata($region, ['clh']);
            $this->fail('install must time out');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Kata was not ready within', $e->getMessage());
            $this->assertStringContainsString('n1 (kata-deploy installing, node not labelled yet)', $e->getMessage());
        }

        $region->refresh();
        $this->assertSame('error', $region->sandbox_kata_status);
        $this->assertStringContainsString('No RuntimeClass was changed', $region->sandbox_kata_status_message);
        $this->assertSame([], $this->runtimeClasses, 'no RuntimeClass before kata-deploy finished');
    }

    public function test_labelled_node_without_the_finished_log_is_not_ready_yet(): void
    {
        $this->addNode('n1', ['katacontainers.io/kata-runtime' => 'true']); // stale label of an earlier install
        $this->fakeKataCluster();

        $this->expectExceptionMessage('n1 (kata-deploy installing)');
        $this->service(2)->installKata($this->kataRegion(), ['clh']);
    }

    public function test_first_install_is_ready_after_kata_deploy_and_creates_runtime_classes(): void
    {
        $this->addNode('n1');
        $this->kataDeployFinishesOnApply();
        $this->fakeKataCluster();
        Log::spy();

        $region = $this->kataRegion();
        $this->service()->installKata($region, ['clh', 'qemu']);

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_kata_status);
        $this->assertSame(['clh', 'qemu'], $region->sandbox_kata_hypervisor);

        $clh = $this->runtimeClasses['kata-clh'];
        $this->assertSame('kata-clh-runr', $clh['handler']);
        $this->assertSame(['cpu' => '250m', 'memory' => '256Mi'], $clh['overhead']['podFixed']);
        $this->assertSame(['katacontainers.io/kata-runtime' => 'true', 'runr.dev/kvm' => 'true'], $clh['scheduling']['nodeSelector']);
        $this->assertSame(['cpu' => '250m', 'memory' => '320Mi'], $this->runtimeClasses['kata-qemu']['overhead']['podFixed']);

        // The custom runtimes' ConfigMap is in place before the installer starts
        $this->assertLessThan(
            $this->callIndex('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-installer'),
            $this->callIndex('PATCH /api/v1/namespaces/runr-sandbox/configmaps/kata-runr-config'),
        );

        $log = $this->lastProgress();
        $this->assertStringContainsString('restarts the k3s service', $log);
        $this->assertStringContainsString('on every target node at about the same time', $log);
        $this->assertStringNotContainsString('1 node at a time', $log);
        $this->assertStringContainsString('kata-deploy finished on 1 node(s)', $log);

        // No blind `helm uninstall` of a release that does not exist (it logged an error every time)
        $this->assertContains('GET /api/v1/namespaces/runr-sandbox/secrets', $this->calls);
        Log::shouldNotHaveReceived('error');
    }

    public function test_reinstall_rolls_the_installer_and_recreates_runtime_classes_with_runr_handlers(): void
    {
        // A RunR 0.4.0 install: installer without the config hash, RuntimeClass on the stock handler
        $this->addNode('runr-test', ['katacontainers.io/kata-runtime' => 'true']);
        $this->daemonSets['kata-installer'] = [
            'metadata' => ['name' => 'kata-installer', 'generation' => 1],
            'spec' => ['template' => ['metadata' => ['labels' => ['runr.dev/component' => 'kata-installer']]]],
        ];
        $this->runtimeClasses['kata-clh'] = ['metadata' => ['name' => 'kata-clh'], 'handler' => 'kata-clh'];
        $this->kataDeployFinishesOnApply();
        $this->fakeKataCluster();

        $region = $this->kataRegion(['sandbox_kata_status' => 'ready', 'sandbox_kata_hypervisor' => ['clh']]);
        $this->service()->installKata($region, ['clh']);

        $this->assertSame('ready', $region->fresh()->sandbox_kata_status);
        $this->assertSame('kata-clh-runr', $this->runtimeClasses['kata-clh']['handler']);
        $this->assertLessThan(
            $this->callIndex('PATCH /apis/node.k8s.io/v1/runtimeclasses/kata-clh'),
            $this->callIndex('DELETE /apis/node.k8s.io/v1/runtimeclasses/kata-clh'),
            'the immutable handler changes by delete + create',
        );

        $log = $this->lastProgress();
        $this->assertStringContainsString('rolling, one node at a time', $log);
        $this->assertStringContainsString('recreated RuntimeClass kata-clh: handler kata-clh → handler kata-clh-runr', $log);
    }

    public function test_install_again_on_ops_0_4_1_installer_writes_the_drop_ins_without_kata_deploy(): void
    {
        // OP after the RunR 0.4.1 install (tests/Fixtures/kata-op)
        $op = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/kata-op/kata-runr-config-0.4.1.json'), true);
        $service = $this->service();
        $spec = (fn () => $this->buildKataInstallerDaemonSet('runr-sandbox', ['clh', 'qemu', 'dragonball'], false, 'k3s'))->call($service)['spec']['template']['spec'];

        $this->addNode('runr-test', ['katacontainers.io/kata-runtime' => 'true']);
        $this->daemonSets['kata-installer'] = [
            'metadata' => ['name' => 'kata-installer', 'generation' => 2],
            'spec' => ['template' => [
                'metadata' => ['labels' => ['runr.dev/component' => 'kata-installer'], 'annotations' => ['runr.dev/kata-config-hash' => $op['kata_config_hash']]],
                'spec' => $spec,
            ]],
        ];
        $this->configMaps['kata-runr-config'] = ['metadata' => ['name' => 'kata-runr-config'], 'data' => $op['data']];
        $this->podLogs['kata-installer-runr-test'] = self::DONE_LOG;
        $this->onApply['kata-dropins'] = fn () => $this->podLogs['kata-dropins-runr-test'] = "runr: kata drop-ins updated (3 runtime handler(s), node runr-test)\n";
        $this->fakeKataCluster();

        $region = $this->kataRegion(['sandbox_kata_status' => 'ready', 'sandbox_kata_hypervisor' => ['clh', 'qemu', 'dragonball']]);
        $service->installKata($region, ['clh', 'qemu', 'dragonball']);

        $this->assertSame('ready', $region->fresh()->sandbox_kata_status);
        // no rollout: same pod template, same (0.4.1) template hash
        $this->assertEquals($spec, $this->daemonSets['kata-installer']['spec']['template']['spec']);
        $this->assertSame($op['kata_config_hash'], $this->daemonSets['kata-installer']['spec']['template']['metadata']['annotations']['runr.dev/kata-config-hash']);
        $this->assertArrayNotHasKey('kata-dropins', $this->daemonSets, 'node script removed');

        $log = $this->lastProgress();
        $this->assertStringContainsString('Kata installer unchanged — nothing is restarted', $log);
        $this->assertStringContainsString('Kata configuration drop-ins (kata-clh-runr, kata-qemu-runr, kata-dragonball-runr) updated in place on 1 node(s)', $log);
        $this->assertStringNotContainsString('rolling, one node at a time', $log);
    }

    public function test_a_crashing_installer_fails_fast_with_its_log(): void
    {
        $this->addNode('n1');
        $this->podStatuses['kata-installer-n1'] = [
            'name' => 'kata-deploy',
            'restartCount' => 4,
            'state' => ['waiting' => ['reason' => 'CrashLoopBackOff']],
            'lastState' => ['terminated' => ['exitCode' => 1, 'message' => "[INFO] Installing\nError: K3s/RKE2: rendered config does not import the drop-in dir 'config-v3.toml.d'\n"]],
        ];
        $this->fakeKataCluster();
        $region = $this->kataRegion();

        $started = microtime(true);
        try {
            $this->service(30)->installKata($region, ['clh']);
            $this->fail('install must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('kata-deploy failed: node n1: the installer keeps crashing (4 restarts)', $e->getMessage());
            $this->assertStringContainsString("does not import the drop-in dir 'config-v3.toml.d'", $e->getMessage());
        }
        $this->assertLessThan(10, microtime(true) - $started, 'no wait for the timeout');
        $this->assertSame('error', $region->fresh()->sandbox_kata_status);
    }

    public function test_unsupported_hypervisor_is_refused_before_touching_the_cluster(): void
    {
        $this->addNode('n1');
        $this->fakeKataCluster();

        $this->expectExceptionMessage('Unsupported Kata hypervisor(s): cloud-hypervisor');
        try {
            $this->service()->installKata($this->kataRegion(), ['cloud-hypervisor']);
        } finally {
            $this->assertSame([], $this->writes());
        }
    }

    // ── Firecracker: devmapper snapshotter ──────────────────────────────────

    public function test_firecracker_sets_up_devmapper_before_kata_deploy_restarts_the_runtime(): void
    {
        $this->addNode('n1');
        $this->onApply['kata-devmapper'] = function () {
            $this->podLogs['kata-devmapper-n1'] = "thin-pool runr-thinpool active\nrunr: devmapper configured\nwaiting for kata-deploy's runtime restart...\n";
        };
        $this->onApply['kata-installer'] = function () {
            $this->labelNode('n1', 'katacontainers.io/kata-runtime', 'true');
            $this->podLogs['kata-installer-n1'] = self::DONE_LOG;
            // kata-deploy's k3s restart loads the devmapper plugin too
            $this->podLogs['kata-devmapper-n1'] .= "devmapper plugin: ok\nrunr: devmapper snapshotter ready\n";
        };
        $this->fakeKataCluster();

        $region = $this->kataRegion();
        $this->service()->installKata($region, ['clh', 'fc'], ['devmapper_pool_gb' => 30]);

        $this->assertSame('ready', $region->fresh()->sandbox_kata_status);

        // devmapper first, so that kata-deploy's single restart loads it
        $this->assertLessThan(
            $this->callIndex('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-installer'),
            $this->callIndex('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-devmapper'),
        );
        $env = array_column($this->daemonSets['kata-devmapper']['spec']['template']['spec']['containers'][0]['env'], 'value', 'name');
        $this->assertSame('kata-deploy', $env['RESTART_MODE'], 'no restart of its own');
        $this->assertSame('30', $env['POOL_GB']);

        $this->assertSame('true', $this->nodes['n1']['metadata']['labels']['runr.dev/devmapper-snapshotter']);
        $this->assertSame('kata-fc-runr', $this->runtimeClasses['kata-fc']['handler']);
        $this->assertSame('true', $this->runtimeClasses['kata-fc']['scheduling']['nodeSelector']['runr.dev/devmapper-snapshotter']);

        $list = $this->bodies['PATCH /api/v1/namespaces/runr-sandbox/configmaps/kata-runr-config']['data']['custom-runtimes.list'];
        $this->assertStringContainsString('kata-fc-runr:fc:devmapper:', $list);

        $log = $this->lastProgress();
        $this->assertStringContainsString("loaded by kata-deploy's runtime restart (no extra restart)", $log);
        $this->assertStringContainsString('devmapper snapshotter loaded on n1', $log);
    }

    public function test_the_thin_pool_size_is_recorded_and_an_install_that_adds_hypervisors_keeps_it(): void
    {
        $this->addNode('n1');
        $this->firecrackerSetsUpOnApply('n1');
        $this->fakeKataCluster();

        $region = $this->kataRegion();
        $this->service()->installKata($region, ['clh', 'fc'], ['devmapper_pool_gb' => 30]);
        $this->assertSame(30, $region->fresh()->sandbox_kata_devmapper_pool_gb, 'the size the nodes got');

        // Adding QEMU later: the console sends the default size, the pool on the nodes stays 30 GiB
        $this->service()->installKata($region->fresh(), ['clh', 'qemu', 'fc'], ['previous_status' => 'ready', 'devmapper_pool_gb' => 20]);

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_kata_status, $region->sandbox_kata_status_message);
        $this->assertSame(30, $region->sandbox_kata_devmapper_pool_gb);
        $env = array_column($this->daemonSets['kata-devmapper']['spec']['template']['spec']['containers'][0]['env'], 'value', 'name');
        $this->assertSame('30', $env['POOL_GB']);
    }

    public function test_a_pool_set_up_before_the_size_was_recorded_keeps_the_size_of_its_daemon_set(): void
    {
        $this->addNode('n1', ['runr.dev/devmapper-snapshotter' => 'true']);
        // Set up by an earlier release with 50 GiB: the region has no size recorded
        $this->daemonSets['kata-devmapper'] = ['metadata' => ['name' => 'kata-devmapper', 'generation' => 1], 'spec' => ['template' => [
            'metadata' => [], 'spec' => ['containers' => [['name' => 'devmapper', 'env' => [['name' => 'POOL_GB', 'value' => '50']]]]],
        ]]];
        $this->firecrackerSetsUpOnApply('n1');
        $this->fakeKataCluster();

        $region = $this->kataRegion(['sandbox_kata_status' => 'ready', 'sandbox_kata_hypervisor' => ['fc']]);
        $this->assertNull($region->sandbox_kata_devmapper_pool_gb);
        $this->assertSame(50, $this->service()->installedDevmapperPoolGb($region->kubeconfig));

        $this->service()->installKata($region, ['clh', 'fc'], ['previous_status' => 'ready', 'devmapper_pool_gb' => 20]);

        $this->assertSame(50, $region->fresh()->sandbox_kata_devmapper_pool_gb);
        $env = array_column($this->daemonSets['kata-devmapper']['spec']['template']['spec']['containers'][0]['env'], 'value', 'name');
        $this->assertSame('50', $env['POOL_GB']);
    }

    /** The devmapper setup and kata-deploy succeed on $node as soon as their DaemonSets are applied. */
    private function firecrackerSetsUpOnApply(string $node): void
    {
        $this->onApply['kata-devmapper'] = function () use ($node) {
            $this->podLogs["kata-devmapper-{$node}"] = "thin-pool runr-thinpool active\nrunr: devmapper configured\n";
        };
        $this->onApply['kata-installer'] = function () use ($node) {
            $this->labelNode($node, 'katacontainers.io/kata-runtime', 'true');
            $this->podLogs["kata-installer-{$node}"] = self::DONE_LOG;
            $this->podLogs["kata-devmapper-{$node}"] .= "devmapper plugin: ok\nrunr: devmapper snapshotter ready\n";
        };
    }

    public function test_devmapper_setup_failure_is_reported_and_kata_deploy_is_not_started(): void
    {
        $this->addNode('n1');
        // a crashed script (terminated with its error)
        $this->onApply['kata-devmapper'] = fn () => $this->podStatuses['kata-devmapper-n1'] = [
            'name' => 'devmapper',
            'restartCount' => 1,
            'state' => ['waiting' => ['reason' => 'CrashLoopBackOff']],
            'lastState' => ['terminated' => ['exitCode' => 1, 'message' => 'runr: ERROR: not enough free disk for a 20 GiB thin-pool under /var/lib/runr/devmapper: 12 GiB free, 30 GiB needed — nothing changed (node n1)']],
        ];
        $this->onApply['kata-devmapper-cleanup'] = fn () => $this->podLogs['kata-devmapper-cleanup-n1'] = "runr: devmapper removed: nothing was set up on this node\n";
        $this->fakeKataCluster();
        $region = $this->kataRegion();

        try {
            $this->service()->installKata($region, ['fc']);
            $this->fail('install must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('devmapper setup failed: not enough free disk', $e->getMessage());
        }

        $this->assertFalse($this->callIndex('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-installer'), 'kata-deploy not started');
        $this->assertSame('error', $region->fresh()->sandbox_kata_status);
        $this->assertArrayNotHasKey('kata-devmapper', $this->daemonSets, 'no crash loop left behind');
    }

    /**
     * OP, 0.4.1 / 0.4.2: adding fc to a working clh / qemu / dragonball install failed in the
     * devmapper step. Since 0.4.3 that leaves the working install as it was: ready, its
     * hypervisors, kata-* mappings allowed — the failure is in the install log.
     */
    public function test_a_failed_firecracker_addition_keeps_the_working_kata_install(): void
    {
        $error = '/var/lib/rancher/k3s/agent/etc/containerd/config.toml is valid but does not configure the devmapper snapshotter — drop-in removed again, nothing restarted (node runr-test)';
        $this->addNode('runr-test');
        $this->onApply['kata-devmapper'] = function () use ($error) {
            $this->podLogs['kata-devmapper-runr-test'] = "thin-pool files kept: /var/lib/runr/devmapper/data (20 GiB, 0 KiB in use), /var/lib/runr/devmapper/meta\n"
                ."thin-pool runr-thinpool active; runr-devmapper.service activates it at boot\nrunr: ERROR: {$error}\n";
        };
        $this->onApply['kata-devmapper-cleanup'] = fn () => $this->podLogs['kata-devmapper-cleanup-runr-test'] =
            "devmapper snapshotter not loaded (skip) — no restart needed\nrunr: devmapper removed: thin-pool runr-thinpool, /var/lib/runr/devmapper and runr-devmapper.service are gone\n";
        $this->fakeKataCluster();
        $region = $this->kataRegion([
            'sandbox_kata_status' => 'installing', // set by the install API before the job runs
            'sandbox_kata_hypervisor' => ['clh', 'qemu', 'dragonball'],
            'sandbox_xaas_runtime' => 'kata-clh',
        ]);

        $started = microtime(true);
        try {
            $this->service()->installKata($region, ['clh', 'qemu', 'dragonball', 'fc'], ['previous_status' => 'ready']);
            $this->fail('the addition must fail');
        } catch (SandboxAddonFailedException $e) {
            $this->assertSame(['fc'], $e->notAdded);
            $this->assertStringStartsWith("fc not added: devmapper setup failed: {$error} — kata-devmapper DaemonSet removed", $e->getMessage());
            $this->assertStringContainsString('the thin-pool files, runr-devmapper.service and config it had created were removed again', $e->getMessage());
        }
        $this->assertLessThan(10, microtime(true) - $started, 'the logged error is read at once');

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_kata_status);
        $this->assertTrue($region->has_sandbox_kata);
        $this->assertSame(['clh', 'qemu', 'dragonball'], $region->sandbox_kata_hypervisor, 'fc not stored');
        $this->assertSame('kata-clh', $region->sandbox_xaas_runtime);
        $log = $region->sandbox_kata_status_message;
        $this->assertStringContainsString("ERROR: devmapper setup failed: {$error}", $log, 'the reason is in the install log');
        $this->assertStringNotContainsString('devmapper setup failed: devmapper setup failed', $log);
        $this->assertStringEndsWith("Kata stays installed with clh, qemu, dragonball; fc not added — the running Kata install was not changed.\n", $log);
        $this->assertArrayNotHasKey('kata-devmapper', $this->daemonSets);
        $this->assertArrayNotHasKey('kata-devmapper-cleanup', $this->daemonSets);
        $this->assertFalse($this->callIndex('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-installer'), 'kata-deploy untouched');
        $this->assertSame([], $this->runtimeClasses, 'RuntimeClasses untouched');

        // kata-* mappings stay allowed (the mapping validation reads status + hypervisors)
        $request = new UpdateSandboxMappingRequest;
        $request->setRouteResolver(fn () => new class($region)
        {
            public function __construct(private $region) {}

            public function parameter($key, $default = null)
            {
                return $key === 'knativeRegion' ? $this->region : $default;
            }
        });
        $this->assertStringContainsString('kata-qemu', implode(',', $request->rules()['sandbox_xaas_runtime']));
    }

    public function test_the_install_job_reports_a_failed_addition_without_failing(): void
    {
        $this->addNode('runr-test');
        $this->onApply['kata-devmapper'] = fn () => $this->podLogs['kata-devmapper-runr-test'] = "runr: ERROR: thin-pool runr-thinpool is not active after runr-devmapper.service (node runr-test)\n";
        $this->onApply['kata-devmapper-cleanup'] = fn () => $this->podLogs['kata-devmapper-cleanup-runr-test'] = "runr: devmapper removed: nothing was set up on this node\n";
        $this->fakeKataCluster();
        $topics = [];
        Event::listen(PlatformEvent::class, function (PlatformEvent $e) use (&$topics) {
            $topics[] = [$e->topic, $e->payload['warning'] ?? null];
        });
        $region = $this->kataRegion(['sandbox_kata_status' => 'installing', 'sandbox_kata_hypervisor' => ['clh', 'qemu', 'dragonball']]);

        (new InstallSandboxJob($region, 'kata', ['clh', 'qemu', 'dragonball', 'fc'], null, ['previous_status' => 'ready']))->handle($this->service());

        $this->assertSame('ready', $region->fresh()->sandbox_kata_status);
        $done = collect($topics)->firstWhere(0, 'sandbox.kata.installed');
        $this->assertNotNull($done, 'no _failed topic: the console keeps Kata ready');
        $this->assertStringContainsString('fc not added: devmapper setup failed: thin-pool runr-thinpool is not active', $done[1]);
        $this->assertNull(collect($topics)->firstWhere(0, 'sandbox.kata.install_failed'));
    }

    /**
     * OP, 0.4.2: the 0.4.1 kata-devmapper DaemonSet was still there with a crash-looping pod (146
     * restarts); the new DaemonSet was applied on top and that pod's old termination message was
     * read as the result in 2 s. Now the old DaemonSet and its pods go first.
     */
    public function test_a_crash_looping_devmapper_pod_of_an_earlier_release_is_not_read_as_the_result(): void
    {
        $this->addNode('runr-test');
        $this->daemonSets['kata-devmapper'] = ['metadata' => ['name' => 'kata-devmapper', 'generation' => 1, 'uid' => 'uid-0.4.1'], 'spec' => ['template' => ['metadata' => []]]];
        $this->stalePods['kata-devmapper'] = [[
            'metadata' => [
                'name' => 'kata-devmapper-plmv8',
                'labels' => ['runr.dev/component' => 'kata-devmapper', 'pod-template-generation' => '1'],
                'ownerReferences' => [['kind' => 'DaemonSet', 'name' => 'kata-devmapper', 'uid' => 'uid-0.4.1']],
            ],
            'spec' => ['nodeName' => 'runr-test'],
            'status' => ['phase' => 'Running', 'containerStatuses' => [[
                'name' => 'devmapper',
                'restartCount' => 146,
                'state' => ['waiting' => ['reason' => 'CrashLoopBackOff']],
                'lastState' => ['terminated' => ['exitCode' => 1, 'message' => 'runr: ERROR: /var/lib/rancher/k3s/agent/etc/containerd/config.toml is valid but does not configure the devmapper snapshotter — drop-in removed again, nothing restarted (node runr-test)']],
            ]]],
        ]];
        $this->onApply['kata-devmapper'] = fn () => $this->podLogs['kata-devmapper-runr-test'] = "thin-pool runr-thinpool active\nrunr: devmapper configured\n";
        $this->onApply['kata-installer'] = function () {
            $this->labelNode('runr-test', 'katacontainers.io/kata-runtime', 'true');
            $this->podLogs['kata-installer-runr-test'] = self::DONE_LOG;
            $this->podLogs['kata-devmapper-runr-test'] .= "devmapper plugin: ok\nrunr: devmapper snapshotter ready\n";
        };
        $this->fakeKataCluster();

        $region = $this->kataRegion(['sandbox_kata_status' => 'ready', 'sandbox_kata_hypervisor' => ['clh', 'qemu', 'dragonball']]);
        $this->service()->installKata($region, ['clh', 'qemu', 'dragonball', 'fc']);

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_kata_status, $region->sandbox_kata_status_message);
        $this->assertSame(['clh', 'qemu', 'dragonball', 'fc'], $region->sandbox_kata_hypervisor);
        $this->assertLessThan(
            $this->callIndex('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-devmapper'),
            $this->callIndex('DELETE /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-devmapper'),
            'the earlier DaemonSet goes before the new one is applied',
        );
        $this->assertStringNotContainsString('devmapper setup failed', $region->sandbox_kata_status_message);
    }

    public function test_node_script_results_count_only_pods_of_the_current_daemon_set(): void
    {
        $this->addNode('n1');
        $this->daemonSets['kata-dropins'] = ['metadata' => ['name' => 'kata-dropins', 'generation' => 2, 'uid' => 'uid-new'], 'spec' => ['template' => ['metadata' => []]]];
        $crash = fn (string $name, string $generation, string $uid) => [
            'metadata' => [
                'name' => $name,
                'labels' => ['runr.dev/component' => 'kata-dropins', 'pod-template-generation' => $generation],
                'ownerReferences' => [['kind' => 'DaemonSet', 'name' => 'kata-dropins', 'uid' => $uid]],
            ],
            'spec' => ['nodeName' => 'n1'],
            'status' => ['phase' => 'Running', 'containerStatuses' => [[
                'name' => 'dropins', 'restartCount' => 9,
                'lastState' => ['terminated' => ['exitCode' => 1, 'message' => 'runr: ERROR: stale (node n1)']],
            ]]],
        ];
        $this->stalePods['kata-dropins'] = [
            $crash('kata-dropins-old-template', '1', 'uid-new'),   // earlier pod template of this DaemonSet
            $crash('kata-dropins-old-object', '2', 'uid-old'),     // a deleted DaemonSet of the same name
        ];
        $this->podLogs['kata-dropins-n1'] = "runr: kata drop-ins updated (1 runtime handler(s), node n1)\n";
        $this->fakeKataCluster();

        $k8s = new KubernetesClient($this->kataKubeconfig());
        $nodes = (fn () => $this->waitForNodeScriptPods($k8s, 'kata-dropins', ['runr: kata drop-ins updated'], 'Kata drop-in update'))
            ->call($this->service());

        $this->assertSame(['n1'], $nodes);
    }

    /**
     * OP on 2026-10-01 (0.4.2, after the failed fc attempts): Kata ready with clh, qemu and
     * dragonball (installer current), no kata-devmapper DaemonSet, no pool, no drop-in, no node
     * labelled for devmapper. (The stale runr-devmapper.service on the node is the node script's
     * part — KataManifestTest.)
     */
    public function test_firecracker_install_from_ops_current_state(): void
    {
        $service = $this->service();
        $three = ['clh', 'qemu', 'dragonball'];
        $current = (fn () => $this->buildKataInstallerDaemonSet('runr-sandbox', $three, false, 'k3s'))->call($service);
        $current['metadata']['generation'] = 2;
        $this->addNode('runr-test', ['katacontainers.io/kata-runtime' => 'true']);
        $this->daemonSets['kata-installer'] = $current;
        foreach ((fn () => $this->buildKataRuntimeClasses($three))->call($service) as $rc) {
            $this->runtimeClasses[$rc['metadata']['name']] = $rc;
        }
        $this->podLogs['kata-installer-runr-test'] = self::DONE_LOG;
        $this->onApply['kata-devmapper'] = fn () => $this->podLogs['kata-devmapper-runr-test'] =
            "stopping runr-devmapper.service\nsparse thin-pool files created\nthin-pool runr-thinpool active\nrunr: devmapper configured\n";
        $this->onApply['kata-installer'] = function () {
            $this->podLogs['kata-installer-runr-test'] = self::DONE_LOG;
            $this->podLogs['kata-devmapper-runr-test'] .= "devmapper plugin: ok\nrunr: devmapper snapshotter ready\n";
        };
        $this->fakeKataCluster();

        $region = $this->kataRegion(['sandbox_kata_status' => 'installing', 'sandbox_kata_hypervisor' => $three]);
        $service->installKata($region, ['clh', 'qemu', 'dragonball', 'fc'], ['previous_status' => 'ready', 'devmapper_pool_gb' => 20]);

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_kata_status, $region->sandbox_kata_status_message);
        $this->assertSame(['clh', 'qemu', 'dragonball', 'fc'], $region->sandbox_kata_hypervisor);
        $this->assertSame('kata-fc-runr', $this->runtimeClasses['kata-fc']['handler']);
        $this->assertSame('true', $this->nodes['runr-test']['metadata']['labels']['runr.dev/devmapper-snapshotter']);
        $this->assertNotContains('DELETE /apis/node.k8s.io/v1/runtimeclasses/kata-clh', $this->calls, 'working RuntimeClasses untouched');

        $env = array_column($this->daemonSets['kata-devmapper']['spec']['template']['spec']['containers'][0]['env'], 'value', 'name');
        $this->assertSame('kata-deploy', $env['RESTART_MODE'], 'loaded by kata-deploy\'s restart, none of its own');
        $log = $this->lastProgress();
        $this->assertStringContainsString('rolling, one node at a time', $log);
        $this->assertStringContainsString('devmapper snapshotter loaded on runr-test', $log);
    }

    public function test_a_devmapper_failure_keeps_a_setup_that_worked_before(): void
    {
        $this->addNode('n1', ['runr.dev/devmapper-snapshotter' => 'true']);
        $this->onApply['kata-devmapper'] = fn () => $this->podLogs['kata-devmapper-n1'] = "runr: ERROR: systemctl restart k3s failed (node n1)\n";
        $this->fakeKataCluster();

        try {
            $this->service()->installKata($this->kataRegion(), ['fc']);
            $this->fail('install must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringEndsWith('systemctl restart k3s failed (node n1) — kata-devmapper DaemonSet removed', $e->getMessage());
        }

        $this->assertNotContains('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-devmapper-cleanup', $this->calls, 'working pool not rolled back');
        $this->assertSame('true', $this->nodes['n1']['metadata']['labels']['runr.dev/devmapper-snapshotter']);
    }

    public function test_install_without_firecracker_removes_an_unfinished_devmapper_setup(): void
    {
        // OP after the failed fc attempt: the kata-devmapper DaemonSet still there, no node labelled
        $this->addNode('runr-test');
        $this->daemonSets['kata-devmapper'] = ['metadata' => ['name' => 'kata-devmapper', 'generation' => 1], 'spec' => ['template' => ['metadata' => []]]];
        $this->onApply['kata-devmapper-cleanup'] = fn () => $this->podLogs['kata-devmapper-cleanup-runr-test'] = "runr: devmapper removed: thin-pool runr-thinpool, /var/lib/runr/devmapper and runr-devmapper.service are gone\n";
        $this->kataDeployFinishesOnApply();
        $this->fakeKataCluster();

        $region = $this->kataRegion();
        $this->service()->installKata($region, ['clh', 'qemu', 'dragonball']);

        $this->assertSame('ready', $region->fresh()->sandbox_kata_status);
        $this->assertArrayNotHasKey('kata-devmapper', $this->daemonSets);
        $this->assertLessThan(
            $this->callIndex('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-installer'),
            $this->callIndex('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-devmapper-cleanup'),
        );
    }

    public function test_an_unreachable_reverb_does_not_fail_the_install(): void
    {
        // 0.4.1 upgrade on OP: region:reconcile queued the install while Reverb restarted —
        // "Pusher error: cURL error 7" failed it at its first progress line
        Event::listen(PlatformEvent::class, function () {
            throw new \RuntimeException('Pusher error: cURL error 7: Failed to connect to runr-reverb port 8080');
        });
        $this->addNode('n1');
        $this->kataDeployFinishesOnApply();
        $this->fakeKataCluster();

        $region = $this->kataRegion();
        $this->service()->installKata($region, ['clh']);

        $this->assertSame('ready', $region->fresh()->sandbox_kata_status);
    }

    public function test_job_completion_broadcast_failure_does_not_fail_the_job(): void
    {
        Event::listen(PlatformEvent::class, function () {
            throw new \RuntimeException('Pusher error: cURL error 7');
        });
        $region = $this->kataRegion(['sandbox_kata_status' => 'installing']);

        (new InstallSandboxJob($region, 'kata', ['clh']))->failed(new \RuntimeException('devmapper setup failed: boom'));

        $this->assertSame('error', $region->fresh()->sandbox_kata_status);
        $this->assertStringContainsString('devmapper setup failed: boom', $region->fresh()->sandbox_kata_status_message);
    }

    // ── Uninstall ───────────────────────────────────────────────────────────

    public function test_uninstall_is_refused_while_pods_run_with_kata(): void
    {
        $this->addNode('n1', ['katacontainers.io/kata-runtime' => 'true']);
        $this->daemonSets['kata-installer'] = ['metadata' => ['name' => 'kata-installer', 'generation' => 1], 'spec' => ['template' => ['metadata' => []]]];
        $this->clusterPods = [
            ['metadata' => ['namespace' => 'fn', 'name' => 'hello-00001-deployment-abc'], 'spec' => ['runtimeClassName' => 'kata-qemu'], 'status' => ['phase' => 'Running']],
            ['metadata' => ['namespace' => 'fn', 'name' => 'pending'], 'spec' => ['runtimeClassName' => 'kata-clh'], 'status' => ['phase' => 'Pending']],
            ['metadata' => ['namespace' => 'fn', 'name' => 'gv'], 'spec' => ['runtimeClassName' => 'gvisor'], 'status' => ['phase' => 'Running']],
        ];
        $this->fakeKataCluster();
        $region = $this->kataRegion(['sandbox_kata_status' => 'ready', 'sandbox_kata_hypervisor' => ['qemu'], 'sandbox_xaas_runtime' => 'kata-qemu']);

        try {
            $this->service()->uninstallKata($region);
            $this->fail('uninstall must be refused');
        } catch (SandboxInUseException $e) {
            $this->assertStringContainsString('1 running pod(s) still use Kata Containers (fn/hello-00001-deployment-abc)', $e->getMessage());
        }

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_kata_status);
        $this->assertStringStartsWith('Uninstall refused:', $region->sandbox_kata_status_message);
        $this->assertSame('kata-qemu', $region->sandbox_xaas_runtime, 'mappings untouched');
        $this->assertSame([], $this->writes(), 'nothing changed on the cluster');
    }

    public function test_uninstall_waits_for_kata_deploy_cleanup_before_removing_its_rbac(): void
    {
        $this->addNode('n1', ['katacontainers.io/kata-runtime' => 'true']);
        $this->daemonSets['kata-installer'] = ['metadata' => ['name' => 'kata-installer', 'generation' => 1], 'spec' => ['template' => ['metadata' => []]]];
        $this->runtimeClasses['kata-clh'] = ['metadata' => ['name' => 'kata-clh'], 'handler' => 'kata-clh-runr'];
        $this->fakeKataCluster();
        Log::spy();
        $region = $this->kataRegion(['sandbox_kata_status' => 'ready', 'sandbox_kata_hypervisor' => ['clh'], 'sandbox_faas_runtime' => 'kata-clh']);

        $this->service()->uninstallKata($region);

        $region->refresh();
        $this->assertSame('not_installed', $region->sandbox_kata_status);
        $this->assertSame('runc', $region->sandbox_faas_runtime);
        $this->assertArrayNotHasKey('kata-clh', $this->runtimeClasses);

        $deleteDs = $this->callIndex('DELETE /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-installer');
        $this->assertNotFalse($deleteDs);
        // RuntimeClasses first (no new Kata pods), RBAC re-applied for kata-deploy's cleanup, removed only after it
        $this->assertLessThan($deleteDs, $this->callIndex('DELETE /apis/node.k8s.io/v1/runtimeclasses/kata-clh'));
        $this->assertLessThan($deleteDs, $this->callIndex('PATCH /apis/rbac.authorization.k8s.io/v1/clusterroles/kata-installer-role'));
        $this->assertGreaterThan($deleteDs, $this->callIndex('DELETE /apis/rbac.authorization.k8s.io/v1/clusterroles/kata-installer-role'));
        $this->assertGreaterThan($deleteDs, $this->callIndex('DELETE /api/v1/namespaces/runr-sandbox/configmaps/kata-runr-config'));

        $this->assertNotContains('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-devmapper-cleanup', $this->calls, 'no devmapper was set up');
        Log::shouldNotHaveReceived('error');
    }

    public function test_uninstall_removes_the_devmapper_thin_pool_where_nothing_uses_it(): void
    {
        $this->addNode('n1', ['katacontainers.io/kata-runtime' => 'true', 'runr.dev/devmapper-snapshotter' => 'true']);
        $this->addNode('n2', ['katacontainers.io/kata-runtime' => 'true', 'runr.dev/devmapper-snapshotter' => 'true']);
        $this->daemonSets['kata-installer'] = ['metadata' => ['name' => 'kata-installer', 'generation' => 1], 'spec' => ['template' => ['metadata' => []]]];
        $this->daemonSets['kata-devmapper'] = ['metadata' => ['name' => 'kata-devmapper', 'generation' => 1], 'spec' => ['template' => ['metadata' => []]]];
        $this->onApply['kata-devmapper-cleanup'] = function () {
            $this->podLogs['kata-devmapper-cleanup-n1'] = "k3s restarted\nrunr: devmapper removed: thin-pool runr-thinpool, /var/lib/runr/devmapper and runr-devmapper.service are gone\n";
            $this->podLogs['kata-devmapper-cleanup-n2'] = "runr: devmapper kept: 0 Firecracker VM(s) and 2 container snapshot(s) still use it on this node\n";
        };
        $this->fakeKataCluster();
        $region = $this->kataRegion(['sandbox_kata_status' => 'ready', 'sandbox_kata_hypervisor' => ['fc'], 'sandbox_kata_devmapper_pool_gb' => 30]);

        $this->service()->uninstallKata($region);

        $this->assertSame('not_installed', $region->fresh()->sandbox_kata_status);
        $this->assertNull($region->fresh()->sandbox_kata_devmapper_pool_gb, 'a new Firecracker install chooses its size again');
        // the pool goes only after kata-deploy took the kata-fc handler away
        $this->assertLessThan(
            $this->callIndex('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-devmapper-cleanup'),
            $this->callIndex('DELETE /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-installer'),
        );
        $this->assertArrayNotHasKey('runr.dev/devmapper-snapshotter', $this->nodes['n1']['metadata']['labels']);
        $this->assertSame('true', $this->nodes['n2']['metadata']['labels']['runr.dev/devmapper-snapshotter'], 'kept where still in use');
        $this->assertArrayNotHasKey('kata-devmapper-cleanup', $this->daemonSets, 'cleanup DaemonSet removed');
        $this->assertArrayNotHasKey('kata-devmapper', $this->daemonSets);
    }
}
