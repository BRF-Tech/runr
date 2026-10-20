<?php

namespace Tests\Feature;

use App\Jobs\InstallSandboxJob;
use App\Models\KnativeRegion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesKataCluster;
use Tests\TestCase;

/**
 * POST install-sandbox validates the Kata hypervisors against the allowed set and against what
 * the cluster supports (the console's kata-shims check), and says what the install restarts.
 */
class KataSandboxApiTest extends TestCase
{
    use FakesKataCluster, RefreshDatabase;

    private ?KnativeRegion $region = null;

    private ?User $installer = null;

    private function install(array $payload): TestResponse
    {
        $this->region ??= $this->kataRegion();
        $this->region->update(['sandbox_kata_status' => 'not_installed']);

        // One user per test: the first user of an install is its Owner (sandbox.manage)
        $this->installer ??= User::factory()->create(['username' => 'u'.bin2hex(random_bytes(4))]);

        return $this->actingAs($this->installer, 'sanctum')
            ->postJson("/api/knative-regions/{$this->region->id}/install-sandbox", $payload);
    }

    public function test_unknown_hypervisors_are_rejected(): void
    {
        $this->addNode('n1');
        $this->fakeKataCluster();
        Queue::fake();

        $errors = $this->install(['runtime' => 'kata', 'hypervisors' => ['clh', 'cloud-hypervisor']])
            ->assertStatus(422)
            ->json('errors');
        $this->assertSame('Unsupported Kata hypervisor "cloud-hypervisor". Allowed: clh, qemu, dragonball, fc.', $errors['hypervisors.1'][0]);

        $this->install(['runtime' => 'kata', 'hypervisors' => ['clh', 'clh']])->assertStatus(422);
        $this->install(['runtime' => 'kata', 'hypervisors' => ['fc'], 'devmapper_pool_gb' => 5])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_firecracker_is_rejected_where_runr_cannot_set_up_devmapper(): void
    {
        $this->addNode('n1', [], 'cri-o://1.31.0', 'v1.31.4');
        $this->fakeKataCluster();
        Queue::fake();

        $this->install(['runtime' => 'kata', 'hypervisors' => ['clh', 'fc']])
            ->assertStatus(422)
            ->assertJsonPath('errors.hypervisors.0', "Kata hypervisor 'fc' cannot run on this cluster: it needs containerd's devmapper snapshotter, which RunR sets up only on containerd nodes (plain, k3s, rke2).");

        Queue::assertNothingPushed();
    }

    public function test_firecracker_on_containerd_nodes_is_queued_with_its_thin_pool_size_and_restart_warning(): void
    {
        $this->addNode('runr-test');
        $this->fakeKataCluster();
        Queue::fake();

        // all four RunR hypervisors side by side (OP: clh, qemu, dragonball + fc)
        $response = $this->install(['runtime' => 'kata', 'hypervisors' => ['clh', 'qemu', 'dragonball', 'fc'], 'devmapper_pool_gb' => 40])->assertStatus(202);

        $this->assertStringContainsString('restarts the container runtime on every target node', $response->json('warning'));
        $this->assertStringContainsString('devmapper snapshotter', $response->json('warning'));
        $this->assertStringContainsString('40 GiB', $response->json('warning'));
        Queue::assertPushed(InstallSandboxJob::class, fn (InstallSandboxJob $job) => $job->runtime === 'kata'
            && $job->hypervisors === ['clh', 'qemu', 'dragonball', 'fc']
            && $job->options === ['previous_status' => 'not_installed', 'devmapper_pool_gb' => 40]);
    }

    public function test_a_region_with_a_thin_pool_keeps_its_size_and_kata_shims_shows_it(): void
    {
        $this->addNode('runr-test', ['runr.dev/devmapper-snapshotter' => 'true']);
        $this->fakeKataCluster();
        Queue::fake();
        $this->region = $this->kataRegion(['sandbox_kata_hypervisor' => ['fc'], 'sandbox_kata_devmapper_pool_gb' => 64]);

        // The console's form for more hypervisors sends the default size along
        $response = $this->install(['runtime' => 'kata', 'hypervisors' => ['qemu', 'fc'], 'devmapper_pool_gb' => 20])->assertStatus(202);

        $this->assertStringContainsString('64 GiB', $response->json('warning'));
        $this->assertSame(64, $response->json('region.sandbox_kata_devmapper_pool_gb'));
        Queue::assertPushed(InstallSandboxJob::class, fn (InstallSandboxJob $job) => $job->options['devmapper_pool_gb'] === 64);

        $devmapper = $this->actingAs($this->installer, 'sanctum')->getJson("/api/knative-regions/{$this->region->id}/kata-shims")->json('devmapper');
        $this->assertSame(64, $devmapper['pool_gb']);
        $this->assertSame(20, $devmapper['default_pool_gb']);
    }

    public function test_kata_install_response_warns_about_the_runtime_restart(): void
    {
        $this->addNode('n1');
        $this->fakeKataCluster();
        Queue::fake();

        $response = $this->install(['runtime' => 'kata', 'hypervisors' => ['clh']])->assertStatus(202);

        $this->assertStringContainsString('restarts the container runtime', $response->json('warning'));
        $this->assertStringNotContainsString('devmapper', $response->json('warning'));
        Queue::assertPushed(InstallSandboxJob::class, fn (InstallSandboxJob $job) => $job->options === ['previous_status' => 'not_installed']);
    }

    public function test_kata_shims_lists_what_runr_installs_and_the_devmapper_state(): void
    {
        $user = User::factory()->create(['username' => 'u'.bin2hex(random_bytes(4))]);

        // k3s node without devmapper: fc installable, RunR sets the snapshotter up
        $this->addNode('runr-test');
        $this->fakeKataCluster();
        $region = $this->kataRegion();
        $shims = $this->actingAs($user, 'sanctum')->getJson("/api/knative-regions/{$region->id}/kata-shims")->assertOk()->json('shims');

        $this->assertSame(['clh', 'qemu', 'dragonball', 'fc'], array_keys($shims));
        $this->assertTrue($shims['fc']['available']);
        $this->assertSame('devmapper', $shims['fc']['setup']);
        $this->assertArrayNotHasKey('setup', $shims['clh']);

        // devmapper set up (node label): nothing to set up any more
        $this->labelNode('runr-test', 'runr.dev/devmapper-snapshotter', 'true');
        Cache::flush(); // GET responses are cached (ResponseCache)
        $shims = $this->actingAs($user, 'sanctum')->getJson("/api/knative-regions/{$region->id}/kata-shims")->json('shims');
        $this->assertTrue($shims['fc']['available']);
        $this->assertArrayNotHasKey('setup', $shims['fc']);
    }

    public function test_kata_shims_blocks_firecracker_on_cri_o(): void
    {
        $this->addNode('n1', [], 'cri-o://1.31.0', 'v1.31.4');
        $this->fakeKataCluster();
        $region = $this->kataRegion();

        $shims = $this->actingAs(User::factory()->create(['username' => 'u'.bin2hex(random_bytes(4))]), 'sanctum')->getJson("/api/knative-regions/{$region->id}/kata-shims")->json('shims');

        $this->assertFalse($shims['fc']['available']);
        $this->assertSame('no_devmapper', $shims['fc']['reason']);
        $this->assertTrue($shims['qemu']['available']);
    }
}
