<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * GET /knative-regions/{id}/gpu is response-cached like every region GET; with ?refresh=1 it
 * reads the cluster and is never answered from (nor stored in) the cache, with Cache-Control
 * no-store.
 */
class GpuRefreshCacheTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    public function test_a_refresh_is_never_served_from_the_response_cache(): void
    {
        config(['cache.default' => 'array']);
        $this->actingAs(User::factory()->create(), 'sanctum');
        $region = $this->gpuRegion([$this->gpuNode('gpu-a', 1)], ['kubeconfig' => $this->fakeKubeconfig()]);
        $this->fakeCluster([
            'GET /api/v1/nodes' => ['items' => [$this->gpuNode('gpu-a', 1), $this->gpuNode('gpu-b', 2)]],
            'GET /api/v1/pods' => ['items' => []],
        ]);

        $this->getJson("/api/knative-regions/{$region->id}/gpu")->assertOk()->assertJsonPath('summary.allocatable', 1);

        // A second GPU node joined: the stored inventory changes behind the cache's back
        // (no model event, so no cache version bump)
        DB::table('knative_regions')->where('id', $region->id)->update(['updated_at' => now()]);   // the query builder has no whereKey(): it was where "key" = …
        $cached = $this->getJson("/api/knative-regions/{$region->id}/gpu")->assertOk();
        $this->assertSame('HIT', $cached->headers->get('X-Cache'), 'plain GETs come from the cache');

        $fresh = $this->getJson("/api/knative-regions/{$region->id}/gpu?refresh=1")->assertOk();
        $this->assertNotSame('HIT', $fresh->headers->get('X-Cache'));
        $this->assertStringContainsString('no-store', (string) $fresh->headers->get('Cache-Control'));
        $fresh->assertJsonPath('summary.allocatable', 3);

        // twice in a row: still read from the cluster
        $again = $this->getJson("/api/knative-regions/{$region->id}/gpu?refresh=1")->assertOk();
        $this->assertNotSame('HIT', $again->headers->get('X-Cache'));
        $this->assertCount(2, array_filter($this->k8sCalls, fn ($c) => $c['method'] === 'GET' && $c['path'] === '/api/v1/nodes'));
    }
}
