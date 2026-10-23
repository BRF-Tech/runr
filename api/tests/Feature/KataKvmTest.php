<?php

namespace Tests\Feature;

use App\Events\PlatformEvent;
use App\Jobs\InstallSandboxJob;
use App\Jobs\ProbeKvmJob;
use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\Steps\KataSandboxStep;
use App\Services\SandboxInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesKataCluster;
use Tests\TestCase;

/**
 * Kata needs hardware virtualization (/dev/kvm) — not on cloud VMs without nested virtualization
 * (for example many cloud worker flavors). RunR checks the nodes, offers no Kata hypervisor where none has it,
 * refuses the install, keeps Kata pods on the nodes that have it in mixed clusters, and reports
 * existing installs without it (0.4.4).
 */
class KataKvmTest extends TestCase
{
    use FakesKataCluster, RefreshDatabase;

    private const DONE_LOG = "Kata Containers installation completed successfully\n";

    private function user(): User
    {
        return User::factory()->create(['username' => 'u'.bin2hex(random_bytes(4))]);
    }

    private function service(): SandboxInstallService
    {
        $service = app(SandboxInstallService::class);
        $service->kataWaitSeconds = 3;
        $service->kataCleanupSeconds = 3;
        $service->kataDevmapperSeconds = 3;
        $service->kvmProbeSeconds = 2;
        $service->kataPollSeconds = 1;
        $this->app->instance(SandboxInstallService::class, $service);

        return $service;
    }

    private function shims(KnativeRegion $region, string $query = ''): array
    {
        Cache::flush(); // GET responses are cached (ResponseCache)

        return $this->actingAs($this->user(), 'sanctum')->getJson("/api/knative-regions/{$region->id}/kata-shims{$query}")->assertOk()->json();
    }

    // ── kata-shims ──────────────────────────────────────────────────────────

    public function test_no_kata_hypervisor_is_offered_where_no_node_has_kvm(): void
    {
        $this->addNode('cloud-worker-0', ['runr.dev/kvm' => 'false']);
        $this->addNode('cloud-worker-1', ['runr.dev/kvm' => 'false']);
        $this->fakeKataCluster();
        Queue::fake();

        $data = $this->shims($this->kataRegion());

        foreach (['clh', 'qemu', 'dragonball', 'fc'] as $hv) {
            $this->assertFalse($data['shims'][$hv]['available'], $hv);
            $this->assertSame('no_kvm', $data['shims'][$hv]['reason'], $hv);
        }
        $this->assertSame('no', $data['kvm']['state']);
        $this->assertFalse($data['kvm']['probing'], 'checked already: nothing to run');
        Queue::assertNothingPushed();
    }

    public function test_unchecked_nodes_are_checked_in_the_background(): void
    {
        $this->addNode('n1');
        $this->addNode('n2', ['feature.node.kubernetes.io/cpu-cpuid.VMX' => 'true']);
        $this->fakeKataCluster();
        Queue::fake();
        $region = $this->kataRegion();

        $data = $this->shims($region);

        $this->assertSame('mixed', $data['kvm']['state'], 'NFD answers for n2 at once');
        $this->assertSame(['n1' => null, 'n2' => true], $data['kvm']['nodes']);
        $this->assertTrue($data['kvm']['probing']);
        $this->assertTrue($data['shims']['clh']['available']);
        Queue::assertPushed(ProbeKvmJob::class, fn (ProbeKvmJob $job) => $job->region->is($region) && ! $job->all);

        // ?refresh=1 checks every node again, also when all have a result (once the check above is done)
        Cache::lock('laravel_unique_job:'.ProbeKvmJob::class.':'.$region->id)->forceRelease();
        $this->labelNode('n1', 'runr.dev/kvm', 'true');
        $this->labelNode('n2', 'runr.dev/kvm', 'true');
        $this->shims($region, '?refresh=1');
        Queue::assertPushed(ProbeKvmJob::class, fn (ProbeKvmJob $job) => $job->all);
    }

    public function test_the_check_labels_each_node_and_tells_the_console(): void
    {
        $this->addNode('n1');
        $this->addNode('n2');
        $this->nodeKvm = ['n1' => true, 'n2' => false];
        $this->fakeKataCluster();
        $events = [];
        Event::listen(PlatformEvent::class, function (PlatformEvent $e) use (&$events) {
            $events[] = [$e->topic, $e->payload];
        });
        $region = $this->kataRegion();

        (new ProbeKvmJob($region))->handle($this->service());

        $this->assertSame('true', $this->nodes['n1']['metadata']['labels']['runr.dev/kvm']);
        $this->assertSame('false', $this->nodes['n2']['metadata']['labels']['runr.dev/kvm']);
        $this->assertStringStartsWith('kvm=no dev_kvm=no cpu=none module=none at=', $this->nodes['n2']['metadata']['annotations']['runr.dev/kvm-probe']);
        $this->assertArrayNotHasKey('kata-kvm-probe', $this->daemonSets, 'the check DaemonSet is removed');
        // the check may be the first of RunR's sandbox runtimes on the cluster: it creates its namespace
        $ns = array_search('PATCH /api/v1/namespaces/runr-sandbox', $this->calls, true);
        $ds = array_search('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-kvm-probe', $this->calls, true);
        $this->assertIsInt($ns, implode("\n", $this->calls));
        $this->assertTrue($ds === false || $ns < $ds, 'the namespace comes before the DaemonSet');
        $this->assertContains(['sandbox.kvm.probed', ['region_id' => $region->id, 'kvm' => 'mixed']], $events);
    }

    // ── install ─────────────────────────────────────────────────────────────

    public function test_install_api_refuses_kata_without_kvm_and_keeps_gvisor(): void
    {
        $this->addNode('cloud-worker-0');
        $this->nodeKvm = ['cloud-worker-0' => false];
        $this->fakeKataCluster();
        $this->service();
        Queue::fake();
        $region = $this->kataRegion();

        // the first user is the organization's Owner (sandbox.manage); later users get the default role
        $owner = $this->user();

        // not checked yet: the install API checks first
        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/knative-regions/{$region->id}/install-sandbox", ['runtime' => 'kata', 'hypervisors' => ['clh']])
            ->assertStatus(422);

        $this->assertSame('no_kvm', $response->json('reason'));
        $this->assertSame('Kata Containers needs hardware virtualization (/dev/kvm), which none of the nodes has (cloud-worker-0: no). Use gVisor or runc on this region.', $response->json('message'));
        $this->assertSame('false', $this->nodes['cloud-worker-0']['metadata']['labels']['runr.dev/kvm']);
        Queue::assertNothingPushed();
        $this->assertSame('not_installed', $region->fresh()->sandbox_kata_status ?? 'not_installed');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/knative-regions/{$region->id}/install-sandbox", ['runtime' => 'gvisor'])
            ->assertStatus(202);
        Queue::assertPushed(InstallSandboxJob::class, fn (InstallSandboxJob $job) => $job->runtime === 'gvisor');
    }

    /**
     * QEMU keeps each guest's memory in a file in /dev/shm (virtio-fs): on nodes that run in a
     * container (Kind, k3d) /dev/shm is Docker's 64 MiB and QEMU dies of SIGBUS while the guest
     * boots. The check reads the size; the install and the console do not offer QEMU there, and the
     * install API says which hypervisors cannot run so the others can be installed.
     */
    public function test_qemu_is_not_offered_where_dev_shm_holds_no_guest(): void
    {
        $this->addNode('kind-worker');
        $this->addNode('kind-worker2');
        $this->nodeShmMb = ['kind-worker' => 64, 'kind-worker2' => 64];
        $this->fakeKataCluster();
        $this->service();
        Queue::fake();
        $region = $this->kataRegion();
        $owner = $this->user();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/knative-regions/{$region->id}/install-sandbox", ['runtime' => 'kata', 'hypervisors' => ['clh', 'qemu', 'dragonball']])
            ->assertStatus(422);

        $this->assertSame(['qemu'], array_keys($response->json('unavailable')));
        $this->assertStringContainsString('/dev/shm is smaller than 1024 MiB', $response->json('unavailable.qemu'));
        $this->assertStringContainsString('kind-worker: 64 MiB, kind-worker2: 64 MiB', $response->json('unavailable.qemu'));
        $this->assertStringStartsWith("Kata hypervisor 'qemu' cannot run on this cluster: the nodes' /dev/shm", $response->json('message'));
        $this->assertStringContainsString(' shm_mb=64 at=', $this->nodes['kind-worker']['metadata']['annotations']['runr.dev/kvm-probe']);
        Queue::assertNothingPushed();

        // the console's selector says the same
        $shims = $this->shims($region)['shims'];
        $this->assertFalse($shims['qemu']['available']);
        $this->assertSame('small_shm', $shims['qemu']['reason']);
        $this->assertSame('kind-worker: 64 MiB, kind-worker2: 64 MiB', $shims['qemu']['detail']);
        $this->assertTrue($shims['clh']['available']);
        $this->assertTrue($shims['dragonball']['available']);

        // the others install
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/knative-regions/{$region->id}/install-sandbox", ['runtime' => 'kata', 'hypervisors' => ['clh', 'dragonball']])
            ->assertStatus(202);
        Queue::assertPushed(InstallSandboxJob::class, fn (InstallSandboxJob $job) => $job->hypervisors === ['clh', 'dragonball']);
    }

    /**
     * Kata's Rust runtime (Dragonball) puts each sandbox into its systemd cgroup over the D-Bus
     * system bus; Kind's node image runs systemd without D-Bus and every Dragonball pod sandbox
     * failed ("add runtime to sandbox cgroup: systemd dbus error: … No such file or directory").
     */
    public function test_dragonball_is_not_offered_where_systemd_runs_without_d_bus(): void
    {
        $this->addNode('kind-worker');
        $this->nodeDbus = ['kind-worker' => 'missing'];
        $this->fakeKataCluster();
        $this->service();
        Queue::fake();
        $region = $this->kataRegion();
        $owner = $this->user();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/knative-regions/{$region->id}/install-sandbox", ['runtime' => 'kata', 'hypervisors' => ['clh', 'qemu', 'dragonball']])
            ->assertStatus(422);

        $this->assertSame(['dragonball'], array_keys($response->json('unavailable')));
        $this->assertStringContainsString('without the D-Bus system bus', $response->json('unavailable.dragonball'));
        $this->assertStringContainsString('(no D-Bus on kind-worker)', $response->json('unavailable.dragonball'));
        $this->assertStringContainsString(' dbus=missing at=', $this->nodes['kind-worker']['metadata']['annotations']['runr.dev/kvm-probe']);

        $shims = $this->shims($region)['shims'];
        $this->assertSame('no_dbus', $shims['dragonball']['reason']);
        $this->assertTrue($shims['clh']['available']);
        $this->assertTrue($shims['qemu']['available']);
    }

    public function test_dragonball_stays_offered_with_d_bus_or_without_systemd(): void
    {
        $this->addNode('host-1');
        $this->addNode('talos-1');
        $this->nodeDbus = ['host-1' => 'yes', 'talos-1' => 'n/a'];
        $this->fakeKataCluster();
        $this->service();
        Queue::fake();
        $region = $this->kataRegion();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/knative-regions/{$region->id}/install-sandbox", ['runtime' => 'kata', 'hypervisors' => ['dragonball']])
            ->assertStatus(202);
    }

    public function test_qemu_stays_offered_where_dev_shm_is_large_or_not_known(): void
    {
        $this->addNode('host-1');
        $this->addNode('host-2', ['runr.dev/kvm' => 'true']); // checked by a release without the size
        $this->nodeShmMb = ['host-1' => 7826];
        $this->fakeKataCluster();
        $this->service();
        Queue::fake();
        $region = $this->kataRegion();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/knative-regions/{$region->id}/install-sandbox", ['runtime' => 'kata', 'hypervisors' => ['qemu']])
            ->assertStatus(202);
        $this->assertTrue($this->shims($region)['shims']['qemu']['available']);
    }

    public function test_mixed_cluster_runs_kata_pods_only_on_nodes_with_kvm(): void
    {
        $this->addNode('n1');
        $this->addNode('n2');
        $this->nodeKvm = ['n1' => true, 'n2' => false];
        $this->onApply['kata-installer'] = function () {
            foreach (['n1', 'n2'] as $node) {
                $this->labelNode($node, 'katacontainers.io/kata-runtime', 'true');
                $this->podLogs["kata-installer-{$node}"] = self::DONE_LOG;
            }
        };
        $this->fakeKataCluster();
        $region = $this->kataRegion();

        $this->service()->installKata($region, ['clh', 'qemu']);

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_kata_status);
        foreach (['kata-clh', 'kata-qemu'] as $rc) {
            $this->assertSame('true', $this->runtimeClasses[$rc]['scheduling']['nodeSelector']['runr.dev/kvm'], $rc);
        }
        $this->assertSame('true', $this->nodes['n1']['metadata']['labels']['runr.dev/kvm']);
        $this->assertSame('false', $this->nodes['n2']['metadata']['labels']['runr.dev/kvm']);
        $this->assertStringContainsString('Hardware virtualization (/dev/kvm): n1: yes; n2: no — Kata pods run only on the nodes with it (runr.dev/kvm=true)', $region->sandbox_kata_status_message);
    }

    public function test_install_stops_when_no_node_answers_the_check(): void
    {
        $this->addNode('n1');
        $this->nodeKvm = ['n1' => null];
        $this->fakeKataCluster();
        $region = $this->kataRegion();

        try {
            $this->service()->installKata($region, ['clh']);
            $this->fail('install must stop');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('/dev/kvm could not be checked on node(s) n1', $e->getMessage());
        }

        $this->assertFalse($this->callIndex('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-installer'), 'kata-deploy not started');
        $this->assertSame('error', $region->fresh()->sandbox_kata_status);
    }

    // ── region:reconcile ────────────────────────────────────────────────────

    private function installedKata(array $nodes): KnativeRegion
    {
        $service = $this->service();
        $hvs = ['clh', 'qemu', 'dragonball'];
        foreach ($nodes as $node) {
            $this->addNode($node, ['katacontainers.io/kata-runtime' => 'true']);
            $this->podLogs["kata-installer-{$node}"] = self::DONE_LOG;
        }
        $this->daemonSets['kata-installer'] = (fn () => $this->buildKataInstallerDaemonSet('runr-sandbox', $hvs, false, 'k3s'))->call($service);
        $this->daemonSets['kata-installer']['metadata']['generation'] = 1;
        // RuntimeClasses of 0.4.3: without the runr.dev/kvm selector
        foreach ((fn () => $this->buildKataRuntimeClasses($hvs))->call($service) as $rc) {
            unset($rc['scheduling']['nodeSelector']['runr.dev/kvm']);
            $this->runtimeClasses[$rc['metadata']['name']] = $rc;
        }
        $this->fakeKataCluster();

        return $this->kataRegion(['sandbox_kata_status' => 'ready', 'sandbox_kata_hypervisor' => $hvs, 'sandbox_xaas_runtime' => 'kata-clh']);
    }

    private function cluster(KnativeRegion $region): ClusterGroup
    {
        return new ClusterGroup('uid:test', [new ReconcileTarget($region)], new KubernetesClient($region->kubeconfig));
    }

    public function test_reconcile_reports_kata_on_a_region_without_kvm_and_leaves_it_installed(): void
    {
        $this->nodeKvm = ['cloud-worker-0' => false];
        $region = $this->installedKata(['cloud-worker-0']);
        Queue::fake();

        $dry = (new KataSandboxStep)->run($this->cluster($region), true);
        $this->assertSame('would check /dev/kvm on node(s) cloud-worker-0 (short-lived unprivileged DaemonSet) and label them runr.dev/kvm — no restart', $dry[0]);
        $this->assertSame([], $this->writes(), 'dry run: read-only');

        $lines = (new KataSandboxStep)->run($this->cluster($region), false);

        $this->assertSame('checked /dev/kvm: cloud-worker-0: no (node label runr.dev/kvm)', $lines[0]);
        $this->assertSame('warning: Kata is installed on region op but none of its nodes has hardware virtualization (/dev/kvm) — cloud-worker-0: no. '
            .'Kata pods cannot start there: map the project types to gVisor or runc, then uninstall Kata from the Sandbox tab. Nothing else was changed.', $lines[1]);
        $this->assertCount(2, $lines);
        $this->assertSame('ready', $region->fresh()->sandbox_kata_status, 'not uninstalled');
        $this->assertSame('kata-clh', $region->fresh()->sandbox_xaas_runtime);
        $this->assertArrayNotHasKey('runr.dev/kvm', $this->runtimeClasses['kata-clh']['scheduling']['nodeSelector'], 'RuntimeClasses untouched');
        Queue::assertNothingPushed();
    }

    public function test_reconcile_restricts_kata_runtime_classes_to_kvm_nodes(): void
    {
        $this->nodeKvm = ['n1' => true, 'n2' => false];
        $region = $this->installedKata(['n1', 'n2']);

        $lines = (new KataSandboxStep)->run($this->cluster($region), false);

        $this->assertSame('checked /dev/kvm: n1: yes; n2: no (node label runr.dev/kvm)', $lines[0]);
        $this->assertSame('note: Kata pods run only on the node(s) with /dev/kvm (runr.dev/kvm=true): n1 — n1: yes; n2: no', $lines[1]);
        $this->assertStringStartsWith('updated RuntimeClass kata-clh (handler kata-clh-runr, overhead 250m CPU / 256Mi, nodeSelector katacontainers.io/kata-runtime=true,runr.dev/kvm=true)', $lines[2]);
        $this->assertSame('true', $this->runtimeClasses['kata-dragonball']['scheduling']['nodeSelector']['runr.dev/kvm']);
        $this->assertNotContains('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-installer', $this->calls, 'no kata-deploy re-run, no restart');

        // a second run: nodes checked, RuntimeClasses current — only the mixed-cluster note
        $this->assertSame(['note: Kata pods run only on the node(s) with /dev/kvm (runr.dev/kvm=true): n1 — n1: yes; n2: no'],
            (new KataSandboxStep)->run($this->cluster($region), false));
    }
}
