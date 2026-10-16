<?php

namespace Tests\Feature;

use App\Jobs\InstallSandboxJob;
use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\Steps\KataSandboxStep;
use App\Services\SandboxInstallService;
use App\Support\Images\RegistryMove;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesKataCluster;
use Tests\TestCase;

/**
 * region:reconcile brings a Kata install of an earlier release to 0.4.1's desired state: a
 * changed installer re-runs kata-deploy (never silently — the restart is reported, and done
 * once through the region's install job), RuntimeClasses alone are updated in place.
 * The OP fixture is OP's state after the RunR 0.4.0 install of 2026-09-30.
 */
class KataSandboxReconcileStepTest extends TestCase
{
    use FakesKataCluster, RefreshDatabase;

    private const OP_HYPERVISORS = ['clh', 'qemu', 'dragonball'];

    private function desired(string $what, array $hypervisors = self::OP_HYPERVISORS): array
    {
        $service = app(SandboxInstallService::class);

        return match ($what) {
            'installer' => (fn () => $this->buildKataInstallerDaemonSet('runr-sandbox', $hypervisors, false, 'k3s'))->call($service),
            'runtime_classes' => (fn () => $this->buildKataRuntimeClasses($hypervisors))->call($service),
        };
    }

    /** OP after the RunR 0.4.0 install: single k3s node, 0.4.0 installer, stock handlers, no overhead. */
    private function opCluster(): KnativeRegion
    {
        $this->addNode('runr-test', [
            'katacontainers.io/kata-runtime' => 'true',
            'node-role.kubernetes.io/control-plane' => 'true',
            'runr.dev/kvm' => 'true', // checked (0.4.4)
        ]);
        $this->daemonSets['kata-installer'] = [
            'metadata' => ['name' => 'kata-installer', 'namespace' => 'runr-sandbox', 'generation' => 1],
            'spec' => ['template' => [
                'metadata' => ['labels' => ['runr.dev/component' => 'kata-installer']],
                'spec' => ['containers' => [['name' => 'kata-deploy', 'image' => 'quay.io/kata-containers/kata-deploy:3.28.0']]],
            ]],
        ];
        foreach (self::OP_HYPERVISORS as $hv) {
            $this->runtimeClasses["kata-{$hv}"] = [
                'metadata' => ['name' => "kata-{$hv}", 'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/component' => 'kata']],
                'handler' => "kata-{$hv}",
            ];
        }
        $this->podLogs['kata-installer-runr-test'] = "Kata Containers installation completed successfully\n";
        $this->fakeKataCluster();

        return $this->kataRegion([
            'sandbox_kata_status' => 'ready',
            'sandbox_kata_hypervisor' => self::OP_HYPERVISORS,
            'sandbox_xaas_runtime' => 'gvisor',
        ]);
    }

    /**
     * OP after the RunR 0.4.1 install (tests/Fixtures/kata-op): the 0.4.1 kata-installer — one hash
     * over its pod spec and the whole ConfigMap — with 0.4.1's drop-ins, RuntimeClasses current.
     */
    private function op041Cluster(): KnativeRegion
    {
        $op = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/kata-op/kata-runr-config-0.4.1.json'), true);

        $this->addNode('runr-test', [
            'katacontainers.io/kata-runtime' => 'true',
            'node-role.kubernetes.io/control-plane' => 'true',
            'runr.dev/kvm' => 'true', // checked (0.4.4)
        ]);
        $this->daemonSets['kata-installer'] = [
            'metadata' => ['name' => 'kata-installer', 'namespace' => 'runr-sandbox', 'generation' => 2,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/component' => 'kata']],
            'spec' => ['template' => [
                'metadata' => ['labels' => ['runr.dev/component' => 'kata-installer'], 'annotations' => ['runr.dev/kata-config-hash' => $op['kata_config_hash']]],
                'spec' => $this->desired('installer')['spec']['template']['spec'],
            ]],
        ];
        $this->configMaps['kata-runr-config'] = ['metadata' => ['name' => 'kata-runr-config', 'namespace' => 'runr-sandbox'], 'data' => $op['data']];
        foreach ($this->desired('runtime_classes') as $rc) {
            $this->runtimeClasses[$rc['metadata']['name']] = $rc;
        }
        $this->podLogs['kata-installer-runr-test'] = "Kata Containers installation completed successfully\n";
        $this->onApply['kata-dropins'] = fn () => $this->podLogs['kata-dropins-runr-test'] =
            "kata-clh-runr: drop-in updated (/custom-runtimes/kata-clh-runr/config.d/50-overrides.toml)\nrunr: kata drop-ins updated (3 runtime handler(s), node runr-test)\n";
        $this->fakeKataCluster();

        return $this->kataRegion([
            'sandbox_kata_status' => 'ready',
            'sandbox_kata_hypervisor' => self::OP_HYPERVISORS,
            'sandbox_xaas_runtime' => 'gvisor',
        ]);
    }

    public function test_dry_run_on_op_0_4_1_updates_only_the_drop_ins_without_restart(): void
    {
        $region = $this->op041Cluster();

        $lines = (new KataSandboxStep)->run($this->cluster($region), true);

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('would update the Kata configuration drop-ins (kata-clh-runr, kata-qemu-runr, kata-dragonball-runr) in place on 1 node(s): ', $lines[0]);
        $this->assertStringEndsWith('— no restart (kata-deploy does not run; Kata pods started afterwards use them, running ones keep theirs)', $lines[0]);
        $this->assertSame([], $this->writes(), 'dry run: read-only');
    }

    public function test_apply_on_op_0_4_1_writes_the_drop_ins_in_place_and_rolls_nothing(): void
    {
        $region = $this->op041Cluster();
        $template = $this->daemonSets['kata-installer']['spec']['template'];
        Queue::fake();

        $lines = (new KataSandboxStep)->run($this->cluster($region), false);

        $this->assertSame(['Kata configuration drop-ins (kata-clh-runr, kata-qemu-runr, kata-dragonball-runr) updated in place on 1 node(s) — '
            .'nothing restarted: Kata pods started from now on use them, running ones keep theirs until they are replaced'], $lines);
        Queue::assertNothingPushed();
        $this->assertSame('ready', $region->fresh()->sandbox_kata_status);

        // kata-deploy not re-run: the installer's pod template is exactly what it was
        $ds = $this->daemonSets['kata-installer'];
        $this->assertEquals($template['spec'], $ds['spec']['template']['spec']);
        $this->assertSame($template['metadata']['annotations'], $ds['spec']['template']['metadata']['annotations']);
        // it records what its nodes run now
        $desired = $this->desired('installer');
        $this->assertSame($desired['metadata']['annotations'], $ds['metadata']['annotations']);
        // the ConfigMap has the new drop-ins (kata-deploy's next run copies them), the node script ran and is gone
        $hypervisors = self::OP_HYPERVISORS;
        $this->assertSame(
            (fn () => $this->buildKataConfigMap('runr-sandbox', $hypervisors, 'k3s'))->call(app(SandboxInstallService::class))['data'],
            $this->configMaps['kata-runr-config']['data'],
        );
        $this->assertContains('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-dropins', $this->calls);
        $this->assertArrayNotHasKey('kata-dropins', $this->daemonSets);
        // the ConfigMap changes only after the installer recorded the drop-ins its nodes had
        $patches = array_keys(array_filter($this->calls, fn ($c) => $c === 'PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-installer'));
        $this->assertLessThan($this->callIndex('PATCH /api/v1/namespaces/runr-sandbox/configmaps/kata-runr-config'), $patches[0]);

        // Second run: nothing left to do
        $this->assertSame([], (new KataSandboxStep)->run($this->cluster($region), false));
    }

    public function test_a_failed_drop_in_update_is_retried_by_the_next_reconcile_without_kata_deploy(): void
    {
        $region = $this->op041Cluster();
        $this->onApply['kata-dropins'] = fn () => $this->podLogs['kata-dropins-runr-test'] = "runr: ERROR: /custom-runtimes/kata-qemu-runr/config.d does not exist (node runr-test)\n";
        $service = app(SandboxInstallService::class);
        $service->kataDevmapperSeconds = 3;
        $service->kataPollSeconds = 1;
        $this->app->instance(SandboxInstallService::class, $service);
        Queue::fake();

        try {
            (new KataSandboxStep)->run($this->cluster($region), false);
            $this->fail('the step must report the failed drop-in update');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Kata drop-in update failed: /custom-runtimes/kata-qemu-runr/config.d does not exist', $e->getMessage());
        }
        $this->assertArrayNotHasKey('kata-dropins', $this->daemonSets, 'node script removed');

        // the installer recorded the old drop-ins: the next run tries again — still without kata-deploy
        $dry = (new KataSandboxStep)->run($this->cluster($region), true);
        $this->assertStringStartsWith('would update the Kata configuration drop-ins', $dry[0]);
        Queue::assertNothingPushed();
    }

    private function cluster(KnativeRegion $region): ClusterGroup
    {
        return new ClusterGroup('uid:op', [new ReconcileTarget($region)], new KubernetesClient($region->kubeconfig));
    }

    /**
     * A plain containerd node with Kata installed by RunR 0.5.0: this release's installer with its
     * config-fixup init container image under RunR's image namespace up to 0.5.0 (ghcr.io/runrsh;
     * $namespace RegistryMove::FLAT: RunR 0.5.1's, directly under ghcr.io/brf-tech).
     * $dropIns: the drop-in hash the installer recorded (null: this release's).
     *
     * @return array{0: KnativeRegion, 1: string} the region and the installer hash 0.5.0 recorded
     */
    private function containerd050Cluster(?string $dropIns = null, string $namespace = RegistryMove::FROM): array
    {
        $this->addNode('node-1', ['katacontainers.io/kata-runtime' => 'true', 'runr.dev/kvm' => 'true'], 'containerd://1.7.27', 'v1.32.0');
        $service = app(SandboxInstallService::class);
        $hypervisors = self::OP_HYPERVISORS;
        $desired = (fn () => $this->buildKataInstallerDaemonSet('runr-sandbox', $hypervisors, false, 'containerd'))->call($service);
        $data = (fn () => $this->buildKataConfigMap('runr-sandbox', $hypervisors, 'containerd'))->call($service)['data'];

        $live = RegistryMove::legacy($desired, $namespace);
        $this->assertSame("{$namespace}/reqs/busybox:latest", $live['spec']['template']['spec']['initContainers'][0]['image']);
        $hash = SandboxInstallService::kataInstallerHash($live['spec']['template']['spec'], $data);
        $this->assertNotSame($desired['metadata']['annotations'][SandboxInstallService::KATA_INSTALLER_HASH_ANNOTATION], $hash);
        $live['metadata']['annotations'][SandboxInstallService::KATA_INSTALLER_HASH_ANNOTATION] = $hash;
        $live['spec']['template']['metadata']['annotations'][SandboxInstallService::KATA_CONFIG_HASH_ANNOTATION] = $hash;
        if ($dropIns !== null) {
            $live['metadata']['annotations'][SandboxInstallService::KATA_DROPINS_HASH_ANNOTATION] = $dropIns;
        }
        $live['metadata']['generation'] = 1;
        $this->daemonSets['kata-installer'] = $live;
        $this->configMaps['kata-runr-config'] = ['metadata' => ['name' => 'kata-runr-config', 'namespace' => 'runr-sandbox'], 'data' => $data];
        foreach ($this->desired('runtime_classes') as $rc) {
            $this->runtimeClasses[$rc['metadata']['name']] = $rc;
        }
        $this->podLogs['kata-installer-node-1'] = "Kata Containers installation completed successfully\n";
        $this->onApply['kata-dropins'] = fn () => $this->podLogs['kata-dropins-node-1'] = "runr: kata drop-ins updated (3 runtime handler(s), node node-1)\n";
        $this->fakeKataCluster();

        return [$this->kataRegion(['sandbox_kata_status' => 'ready', 'sandbox_kata_hypervisor' => self::OP_HYPERVISORS]), $hash];
    }

    public function test_the_image_namespace_move_alone_does_not_re_run_kata_deploy(): void
    {
        [$region] = $this->containerd050Cluster();
        Queue::fake();

        $drift = app(SandboxInstallService::class)->kataDrift(new KubernetesClient($region->kubeconfig), self::OP_HYPERVISORS);
        $this->assertSame(['current', 'current'], [$drift['installer'], $drift['dropins']]);

        $this->assertSame([], (new KataSandboxStep)->run($this->cluster($region), true));
        $this->assertSame([], (new KataSandboxStep)->run($this->cluster($region), false));
        $this->assertSame([], $this->writes(), 'the installer keeps its pods (kata-deploy would restart containerd)');
        Queue::assertNothingPushed();
    }

    public function test_the_flat_image_namespace_of_0_5_1_alone_does_not_re_run_kata_deploy_either(): void
    {
        [$region] = $this->containerd050Cluster(null, RegistryMove::FLAT);
        Queue::fake();

        $drift = app(SandboxInstallService::class)->kataDrift(new KubernetesClient($region->kubeconfig), self::OP_HYPERVISORS);
        $this->assertSame(['current', 'current'], [$drift['installer'], $drift['dropins']]);

        $this->assertSame([], (new KataSandboxStep)->run($this->cluster($region), false));
        $this->assertSame([], $this->writes(), 'the installer keeps its pods');
        Queue::assertNothingPushed();
    }

    public function test_a_drop_in_update_keeps_the_installer_of_the_old_image_namespace_as_it_runs(): void
    {
        [$region, $hash] = $this->containerd050Cluster('0123456789abcdef');
        $template = $this->daemonSets['kata-installer']['spec']['template'];
        Queue::fake();

        $lines = (new KataSandboxStep)->run($this->cluster($region), false);

        $this->assertStringStartsWith('Kata configuration drop-ins (kata-clh-runr, kata-qemu-runr, kata-dragonball-runr) updated in place on 1 node(s)', $lines[0]);
        Queue::assertNothingPushed();
        $ds = $this->daemonSets['kata-installer'];
        $this->assertEquals($template['spec'], $ds['spec']['template']['spec'], 'pod template as it runs: nothing rolls');
        $this->assertSame($template['metadata']['annotations'], $ds['spec']['template']['metadata']['annotations']);
        $this->assertSame($hash, $ds['metadata']['annotations'][SandboxInstallService::KATA_INSTALLER_HASH_ANNOTATION]);

        $this->assertSame([], (new KataSandboxStep)->run($this->cluster($region), false), 'nothing left to do');
    }

    /**
     * What region:reconcile's image-registry leaves of that installer: its pod template moved to
     * ghcr.io/brf-tech/runr with its pods pinned (update strategy OnDelete) — they stay on the
     * earlier revision (no pod updated) and carry the template's config hash.
     */
    private function pinInstaller(): void
    {
        $this->daemonSets['kata-installer']['spec']['template']['spec'] = RegistryMove::value($this->daemonSets['kata-installer']['spec']['template']['spec']);
        $this->daemonSets['kata-installer']['spec']['updateStrategy'] = ['type' => 'OnDelete'];
        $this->updatedPods['kata-installer'] = 0;
    }

    public function test_an_installer_whose_pods_image_registry_pinned_counts_as_rolled_out(): void
    {
        [$region] = $this->containerd050Cluster();
        $this->pinInstaller();
        $k8s = new KubernetesClient($region->kubeconfig);
        Queue::fake();

        $rollout = app(SandboxInstallService::class)->kataRolloutStatus($k8s);
        $this->assertTrue($rollout['done'], implode('; ', $rollout['pending']));
        $this->assertSame([], (new KataSandboxStep)->run($this->cluster($region), false));
        $this->assertSame([], $this->writes());
        Queue::assertNothingPushed();

        // not pinned: pods of an earlier revision are a rollout in progress
        $this->daemonSets['kata-installer']['spec']['updateStrategy'] = ['type' => 'RollingUpdate', 'rollingUpdate' => ['maxUnavailable' => 1]];
        $this->assertSame(['installer rollout (0/1 pods updated)'], app(SandboxInstallService::class)->kataRolloutStatus($k8s)['pending']);
    }

    public function test_a_drop_in_update_keeps_a_pinned_installer_moved_and_pinned(): void
    {
        [$region, $hash] = $this->containerd050Cluster('0123456789abcdef');
        $this->pinInstaller();
        $template = $this->daemonSets['kata-installer']['spec']['template'];
        Queue::fake();

        $lines = (new KataSandboxStep)->run($this->cluster($region), false);

        $this->assertStringStartsWith('Kata configuration drop-ins (kata-clh-runr, kata-qemu-runr, kata-dragonball-runr) updated in place on 1 node(s)', $lines[0]);
        Queue::assertNothingPushed();
        $ds = $this->daemonSets['kata-installer'];
        $this->assertEquals($template['spec'], $ds['spec']['template']['spec'], 'the moved pod template stays');
        $this->assertSame('ghcr.io/brf-tech/runr/reqs/busybox:latest', $ds['spec']['template']['spec']['initContainers'][0]['image']);
        $this->assertSame($template['metadata']['annotations'], $ds['spec']['template']['metadata']['annotations']);
        $this->assertSame(['type' => 'OnDelete'], $ds['spec']['updateStrategy'], 'RollingUpdate would re-run kata-deploy on every node');
        $this->assertSame($hash, $ds['metadata']['annotations'][SandboxInstallService::KATA_INSTALLER_HASH_ANNOTATION]);

        $this->assertSame([], (new KataSandboxStep)->run($this->cluster($region), false), 'nothing left to do');
    }

    public function test_dry_run_on_op_reports_the_kata_deploy_re_run_and_its_restarts(): void
    {
        $region = $this->opCluster();

        $lines = (new KataSandboxStep)->run($this->cluster($region), true);

        $this->assertSame([
            "would re-run kata-deploy on 1 node(s) with this release's Kata configuration (runtime handlers kata-<hypervisor>-runr: "
                .'shim, VMM and virtiofsd in the pod cgroup, VM sized from the pod limits); then RuntimeClasses kata-clh, kata-qemu, '
                .'kata-dragonball switch to those handlers with pod overhead kata-clh 250m / 256Mi, kata-qemu 250m / 320Mi, '
                .'kata-dragonball 250m / 256Mi and nodeSelector katacontainers.io/kata-runtime=true,runr.dev/kvm=true (no RuntimeClass changes before every node is done)',
            "restart: kata-deploy's cleanup and reinstall restart the k3s service (containerd, kubelet and, on servers, the Kubernetes API) "
                .'on each node, twice back to back, one node at a time (running pods keep running; on a single-node cluster the Kubernetes API '
                .'is unavailable for ~10–20 s each time)',
        ], $lines);
        $this->assertSame([], $this->writes(), 'dry run: read-only');
        $this->assertSame('ready', $region->fresh()->sandbox_kata_status);
    }

    public function test_apply_on_op_queues_the_install_once_and_reports_the_restart(): void
    {
        $region = $this->opCluster();
        Queue::fake();

        $lines = (new KataSandboxStep)->run($this->cluster($region), false);

        $this->assertStringStartsWith('re-running kata-deploy on 1 node(s)', $lines[0]);
        $this->assertStringContainsString('queued as the Kata install of region op', $lines[0]);
        $this->assertStringStartsWith('restart: ', $lines[1]);
        Queue::assertPushed(InstallSandboxJob::class, 1);
        Queue::assertPushed(InstallSandboxJob::class, fn (InstallSandboxJob $job) => $job->runtime === 'kata' && $job->hypervisors === self::OP_HYPERVISORS);
        $this->assertSame('installing', $region->fresh()->sandbox_kata_status);
        $this->assertSame([], $this->writes(), 'the job does the cluster work');

        // Idempotent: while that install runs the step leaves the region alone
        $again = (new KataSandboxStep)->run($this->cluster($region->fresh()), false);
        $this->assertSame(['skipped: Kata on region op is installing'], $again);
        Queue::assertPushed(InstallSandboxJob::class, 1);
    }

    public function test_current_installer_only_switches_the_runtime_classes_without_restart(): void
    {
        $region = $this->opCluster();
        $this->daemonSets['kata-installer'] = $this->desired('installer') + ['metadata' => []];
        $this->daemonSets['kata-installer']['metadata']['generation'] = 1;

        $dry = (new KataSandboxStep)->run($this->cluster($region), true);
        $this->assertSame(
            'would recreate RuntimeClass kata-clh (the handler kata-clh cannot change in place): handler kata-clh-runr, overhead 250m CPU / 256Mi, '
            .'nodeSelector katacontainers.io/kata-runtime=true,runr.dev/kvm=true — no restart',
            $dry[0],
        );
        $this->assertCount(3, $dry);
        $this->assertSame([], $this->writes());

        $lines = (new KataSandboxStep)->run($this->cluster($region), false);
        $this->assertStringStartsWith('recreated RuntimeClass kata-clh: handler kata-clh → handler kata-clh-runr', $lines[0]);
        $this->assertStringEndsWith('— no restart', $lines[0]);
        $this->assertSame('kata-qemu-runr', $this->runtimeClasses['kata-qemu']['handler']);
        $this->assertSame(['cpu' => '250m', 'memory' => '320Mi'], $this->runtimeClasses['kata-qemu']['overhead']['podFixed']);
        $this->assertNotContains('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-installer', $this->calls, 'kata-deploy not touched');

        // Second run: nothing left to do
        $this->assertSame([], (new KataSandboxStep)->run($this->cluster($region), false));
    }

    public function test_runtime_classes_wait_while_kata_deploy_has_not_finished(): void
    {
        $region = $this->opCluster();
        $this->daemonSets['kata-installer'] = $this->desired('installer') + ['metadata' => []];
        $this->daemonSets['kata-installer']['metadata']['generation'] = 1;
        $this->labelNode('runr-test', 'katacontainers.io/kata-runtime', null);

        $lines = (new KataSandboxStep)->run($this->cluster($region), false);

        $this->assertSame(['skipped: RuntimeClasses kata-clh, kata-qemu, kata-dragonball wait for kata-deploy — runr-test (kata-deploy installing, node not labelled yet)'], $lines);
        $this->assertSame([], $this->writes());
    }

    public function test_up_to_date_install_changes_nothing(): void
    {
        $region = $this->opCluster();
        $this->daemonSets['kata-installer'] = $this->desired('installer') + ['metadata' => []];
        $this->daemonSets['kata-installer']['metadata']['generation'] = 1;
        foreach ($this->desired('runtime_classes') as $rc) {
            $this->runtimeClasses[$rc['metadata']['name']] = $rc;
        }

        $this->assertSame([], (new KataSandboxStep)->run($this->cluster($region), true));
        $this->assertSame([], (new KataSandboxStep)->run($this->cluster($region), false));
        $this->assertSame([], $this->writes());
    }

    public function test_regions_without_kata_are_not_touched(): void
    {
        $this->addNode('n1');
        $this->fakeKataCluster();
        $region = $this->kataRegion();

        $this->assertSame([], (new KataSandboxStep)->run($this->cluster($region), false));
        $this->assertSame([], $this->calls, 'no API call at all');
    }
}
