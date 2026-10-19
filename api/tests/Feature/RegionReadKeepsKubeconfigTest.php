<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Reading a region's cluster never removes its kubeconfig: GET knative-status and GET nodes of
 * an unreachable cluster answer the error and keep RunR's only access to the cluster (it may be
 * back a minute later). And the dedicated workers can be cleared: POST workers with [] releases
 * every node.
 */
class RegionReadKeepsKubeconfigTest extends TestCase
{
    use ActsWithRoles, BuildsAiFixtures, RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signedIn($this->userWithRole('owner'));
        $this->region = KnativeRegion::create([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_operator_status' => 'ready', 'knative_serving_status' => 'ready',
        ]);
    }

    private function unreachableCluster(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException('cURL error 28: Connection timed out'));
    }

    public function test_the_status_of_an_unreachable_cluster_keeps_the_kubeconfig(): void
    {
        $this->unreachableCluster();

        $this->getJson("/api/knative-regions/{$this->region->id}/knative-status?force=1")
            ->assertStatus(400)
            ->assertJsonPath('status', 'error')
            ->assertJsonMissingPath('kubeconfig_cleared');

        $region = $this->region->fresh();
        $this->assertSame($this->fakeKubeconfig(), $region->kubeconfig);
        $this->assertSame('unreachable', $region->cluster_status);
        $this->assertStringContainsString('Connection timed out', (string) $region->cluster_status_message);
    }

    public function test_the_nodes_of_an_unreachable_cluster_change_nothing(): void
    {
        $this->unreachableCluster();
        $before = $this->region->fresh()->only(['knative_status', 'knative_status_message', 'updated_at']);

        $this->getJson("/api/knative-regions/{$this->region->id}/nodes")
            ->assertStatus(400)
            ->assertJsonMissingPath('kubeconfig_cleared');

        $region = $this->region->fresh();
        $this->assertSame($this->fakeKubeconfig(), $region->kubeconfig);
        $this->assertEquals($before, $region->only(['knative_status', 'knative_status_message', 'updated_at']));
    }

    public function test_an_empty_worker_selection_releases_every_node(): void
    {
        $this->region->update(['selected_workers' => ['w1']]);
        $this->fakeCluster([
            'GET /api/v1/nodes' => ['items' => [[
                'metadata' => ['name' => 'w1', 'labels' => ['runr.dev/dedicated' => 'projects', 'node-role.kubernetes.io/knative-worker' => '']],
                'spec' => ['taints' => [['key' => 'runr.dev/dedicated', 'value' => 'projects', 'effect' => 'NoSchedule']]],
                'status' => ['allocatable' => ['cpu' => '4', 'memory' => '8Gi'], 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
            ]]],
            'GET /api/v1/pods' => ['items' => []],
        ]);

        $this->postJson("/api/knative-regions/{$this->region->id}/workers", ['nodes' => []])->assertOk();

        $this->assertSame([], $this->region->fresh()->selected_workers);
        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/api/v1/nodes/w1'), 'the released node loses its label and taint');

        $this->postJson("/api/knative-regions/{$this->region->id}/workers", [])->assertStatus(422)->assertJsonValidationErrors('nodes');
    }
}
