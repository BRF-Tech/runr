<?php

namespace Tests\Feature\Wasm;

use App\Jobs\InstallWasmJob;
use App\Jobs\UninstallWasmJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesWasmCluster;
use Tests\TestCase;

/**
 * GET / POST / DELETE /api/knative-regions/{id}/wasm: the status with the cluster's nodes, the
 * install request (shims, nodes checked against the cluster), uninstall, and their permissions
 * (regions.view to read, sandbox.manage to change).
 */
class WasmApiTest extends TestCase
{
    use FakesWasmCluster, RefreshDatabase;

    private ?User $owner = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addNode('worker-1');
        $this->addNode('worker-2', ['runr.dev/wasm-target' => 'true', 'runr.dev/wasm' => 'true', 'runr.dev/wasm-spin' => 'true']);
        $this->addNode('crio-1', [], 'cri-o://1.31.0', 'v1.31.4');
        $this->fakeWasmCluster();
    }

    /** The first user of an install is its Owner (sandbox.manage). */
    private function owner(): User
    {
        return $this->owner ??= User::factory()->create(['username' => 'owner'.bin2hex(random_bytes(3))]);
    }

    /** A later user gets the default role (Developer): regions.view, no sandbox.manage. */
    private function developer(): User
    {
        $this->owner();

        return User::factory()->create(['username' => 'dev'.bin2hex(random_bytes(3))]);
    }

    public function test_the_status_lists_the_shims_and_the_nodes_with_their_eligibility(): void
    {
        $region = $this->wasmRegion();

        $response = $this->actingAs($this->owner(), 'sanctum')->getJson("/api/knative-regions/{$region->id}/wasm")->assertOk();

        $response->assertJsonPath('status', 'not_installed')
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('spin_runtime', '4.1.0')
            ->assertJsonPath('available_shims.0.id', 'spin')
            ->assertJsonPath('available_shims.0.runtime_class', 'wasmtime-spin-v2')
            ->assertJsonPath('available_shims.1.id', 'wasmtime')
            ->assertJsonPath('cluster_error', null);

        $nodes = collect($response->json('cluster.nodes'))->keyBy('name');
        $this->assertTrue($nodes['worker-1']['eligible']);
        $this->assertSame(['spin'], $nodes['worker-2']['shims']);
        $this->assertTrue($nodes['worker-2']['target']);
        $this->assertSame('runtime', $nodes['crio-1']['reason']);
        $this->assertSame(['kubernetes.podspec-runtimeclassname', 'kubernetes.podspec-nodeselector'], $response->json('cluster.knative_features_to_enable'));
        $this->assertStringContainsString('restarts the container runtime', $response->json('warning'));
    }

    public function test_the_status_without_the_cluster(): void
    {
        $region = $this->wasmRegion(['sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin'], 'sandbox_wasm_nodes' => ['worker-2']]);
        $this->calls = [];

        $this->actingAs($this->owner(), 'sanctum')->getJson("/api/knative-regions/{$region->id}/wasm?cluster=0")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('shims', ['spin'])
            ->assertJsonPath('nodes', ['worker-2'])
            ->assertJsonPath('cluster', null);

        $this->assertSame([], $this->calls, 'no cluster call');
    }

    public function test_install_is_queued_for_every_eligible_node(): void
    {
        Queue::fake();
        $region = $this->wasmRegion();

        $response = $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/knative-regions/{$region->id}/wasm", ['shims' => ['spin']])
            ->assertStatus(202)
            ->assertJsonPath('region.sandbox_wasm_status', 'installing');

        $this->assertStringContainsString('restarts the container runtime once on every selected node', $response->json('warning'));
        Queue::assertPushed(InstallWasmJob::class, fn (InstallWasmJob $job) => $job->shims === ['spin'] && $job->nodes === null
            && $job->region->is($region) && $job->initiatorId === (string) $this->owner()->id);   // the job keeps the id as a string
        $this->assertSame('installing', $region->refresh()->sandbox_wasm_status);
    }

    public function test_install_on_selected_nodes_with_wasmtime(): void
    {
        Queue::fake();
        $region = $this->wasmRegion();

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/knative-regions/{$region->id}/wasm", ['shims' => ['spin', 'wasmtime'], 'nodes' => ['worker-1']])
            ->assertStatus(202);

        Queue::assertPushed(InstallWasmJob::class, fn (InstallWasmJob $job) => $job->shims === ['spin', 'wasmtime'] && $job->nodes === ['worker-1']);
    }

    public function test_a_change_of_a_working_install_tells_the_job_it_was_ready(): void
    {
        Queue::fake();
        $region = $this->wasmRegion(['sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin']]);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/knative-regions/{$region->id}/wasm", ['shims' => ['spin', 'wasmtime']])
            ->assertStatus(202)
            ->assertJsonPath('region.sandbox_wasm_status', 'installing');

        // the job runs on the row marked "installing": a failure must still keep the working install
        Queue::assertPushed(InstallWasmJob::class, fn (InstallWasmJob $job) => $job->previousStatus === 'ready');
    }

    public function test_install_requests_are_validated(): void
    {
        Queue::fake();
        $region = $this->wasmRegion();
        $as = $this->actingAs($this->owner(), 'sanctum');

        $as->postJson("/api/knative-regions/{$region->id}/wasm", ['shims' => ['wasmedge']])
            ->assertStatus(422)
            ->assertJsonPath('errors', fn ($e) => str_contains($e['shims.0'][0], 'Unsupported WebAssembly shim "wasmedge"'));
        $as->postJson("/api/knative-regions/{$region->id}/wasm", ['shims' => []])->assertStatus(422);
        $as->postJson("/api/knative-regions/{$region->id}/wasm", [])->assertStatus(422);

        // nodes are checked against the cluster
        $as->postJson("/api/knative-regions/{$region->id}/wasm", ['shims' => ['spin'], 'nodes' => ['worker-9']])
            ->assertStatus(422)
            ->assertJsonPath('errors.nodes.0', 'Node worker-9 is not part of this cluster.');
        $as->postJson("/api/knative-regions/{$region->id}/wasm", ['shims' => ['spin'], 'nodes' => ['crio-1']])
            ->assertStatus(422)
            ->assertJsonPath('errors.nodes.0', 'Node crio-1 cannot get WebAssembly support (the container runtime is not containerd).');

        Queue::assertNothingPushed();
        $this->assertSame('not_installed', $region->refresh()->sandbox_wasm_status);
    }

    public function test_install_needs_an_eligible_node_knative_serving_and_no_running_operation(): void
    {
        Queue::fake();
        $as = $this->actingAs($this->owner(), 'sanctum');

        $noServing = $this->wasmRegion(['name' => 'b', 'knative_serving_status' => 'not_installed']);
        $as->postJson("/api/knative-regions/{$noServing->id}/wasm", ['shims' => ['spin']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Install Knative Serving on this region first: WebAssembly functions run as Knative Services.');

        $busy = $this->wasmRegion(['name' => 'c', 'sandbox_wasm_status' => 'installing']);
        $as->postJson("/api/knative-regions/{$busy->id}/wasm", ['shims' => ['spin']])->assertStatus(422);

        $demo = $this->wasmRegion(['name' => 'd', 'is_demo' => true]);
        $as->postJson("/api/knative-regions/{$demo->id}/wasm", ['shims' => ['spin']])->assertStatus(422);

        $this->nodes = [];
        $this->addNode('crio-only', [], 'cri-o://1.31.0', 'v1.31.4');
        $region = $this->wasmRegion(['name' => 'e']);
        $as->postJson("/api/knative-regions/{$region->id}/wasm", ['shims' => ['spin']])
            ->assertStatus(422)
            ->assertJsonPath('errors.nodes.0', fn ($m) => str_contains($m, 'No node of this region can get WebAssembly support'));

        Queue::assertNothingPushed();
    }

    public function test_uninstall_is_queued_and_needs_an_install(): void
    {
        Queue::fake();
        $as = $this->actingAs($this->owner(), 'sanctum');

        $none = $this->wasmRegion();
        $as->deleteJson("/api/knative-regions/{$none->id}/wasm")
            ->assertStatus(422)
            ->assertJsonPath('message', 'WebAssembly support is not installed on this region.');

        $ready = $this->wasmRegion(['name' => 'b', 'sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin']]);
        $as->deleteJson("/api/knative-regions/{$ready->id}/wasm")
            ->assertStatus(202)
            ->assertJsonPath('region.sandbox_wasm_status', 'uninstalling');

        Queue::assertPushed(UninstallWasmJob::class, fn (UninstallWasmJob $job) => $job->region->is($ready));
        Queue::assertPushed(UninstallWasmJob::class, 1);
    }

    public function test_reading_needs_regions_view_and_changing_needs_sandbox_manage(): void
    {
        Queue::fake();
        $region = $this->wasmRegion(['sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin']]);
        $developer = $this->developer();

        $this->actingAs($developer, 'sanctum')->getJson("/api/knative-regions/{$region->id}/wasm?cluster=0")
            ->assertOk()
            ->assertJsonPath('can_manage', false);
        $this->actingAs($developer, 'sanctum')->postJson("/api/knative-regions/{$region->id}/wasm", ['shims' => ['spin']])->assertForbidden();
        $this->actingAs($developer, 'sanctum')->deleteJson("/api/knative-regions/{$region->id}/wasm")->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame('ready', $region->refresh()->sandbox_wasm_status);
    }

    public function test_the_region_resource_carries_the_wasm_status(): void
    {
        $region = $this->wasmRegion(['sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin'], 'sandbox_wasm_nodes' => ['worker-2']]);

        $data = collect($this->actingAs($this->owner(), 'sanctum')->getJson('/api/knative-regions')->assertOk()->json('data'))
            ->firstWhere('id', $region->id);

        $this->assertSame('ready', $data['sandbox_wasm_status']);
        $this->assertSame(['spin'], $data['sandbox_wasm_shims']);
        $this->assertSame(['worker-2'], $data['sandbox_wasm_nodes']);
        $this->assertTrue($data['has_sandbox_wasm']);
    }
}
