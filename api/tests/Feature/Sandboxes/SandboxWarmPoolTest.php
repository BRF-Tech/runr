<?php

namespace Tests\Feature\Sandboxes;

use App\Jobs\Sandboxes\RefillSandboxPoolsJob;
use App\Models\Sandbox;
use App\Models\SandboxPool;
use App\Models\SandboxRegionSetting;
use App\Services\Sandboxes\SandboxMetrics;
use App\Services\Sandboxes\SandboxPoolService;
use App\Services\Sandboxes\Tar\TarReader;
use Illuminate\Support\Facades\Queue;

/**
 * Warm pools: refill (start, warming → warm, surplus, stale and disabled pools), the atomic claim
 * on create (same id, labels, egress policy, env + files after the claim, hit counter), misses,
 * spec mismatches and the metrics they feed.
 */
class SandboxWarmPoolTest extends SandboxTestCase
{
    private function pool(array $attributes = []): SandboxPool
    {
        return SandboxPool::create($attributes + [
            'tenant_id' => null,
            'knative_region_id' => $this->region->id,
            'runtime' => 'python',
            'runtime_version' => '3.14',
            'cpu_m' => 500,
            'memory_mb' => 512,
            'disk_mb' => 1024,
            'size' => 2,
            'is_enabled' => true,
        ]);
    }

    private function refill(?string $poolId = null): int
    {
        return app(SandboxPoolService::class)->refill($poolId);
    }

    public function test_refill_starts_warm_pods_and_marks_them_warm_once_running(): void
    {
        $pool = $this->pool(['size' => 2]);

        $this->assertSame(2, $this->refill());
        $members = Sandbox::where('pool_id', $pool->id)->get();
        $this->assertCount(2, $members);
        $this->assertSame(['warming', 'warming'], $members->pluck('status')->all());

        $pod = $this->podOf($members[0]->id);
        $this->assertSame('warm', $pod['metadata']['labels']['runr.dev/sandbox-state']);
        $this->assertSame(strtolower($pool->id), $pod['metadata']['labels']['runr.dev/sandbox-pool']);
        $this->assertSame('kata-fc', $pod['spec']['runtimeClassName']);
        // Pool pods outlive the warm period too
        $this->assertSame(86400 + 3600 + 300, $pod['spec']['activeDeadlineSeconds']);

        // Next run: the pods run → warm; nothing new to start
        $this->assertSame(0, $this->refill());
        $this->assertSame(['warm', 'warm'], Sandbox::where('pool_id', $pool->id)->pluck('status')->all());
    }

    public function test_create_claims_a_warm_pod(): void
    {
        Queue::fake();
        $pool = $this->pool(['size' => 1]);
        $this->refill();
        $this->refill();
        $warm = Sandbox::where('pool_id', $pool->id)->sole();
        $this->assertSame('warm', $warm->status);
        $this->exec->calls = [];

        $data = $this->createSandbox([
            'runtime' => 'python',
            'env' => ['TOKEN' => 'sk-pool-secret'],
            'files' => [['path' => '/workspace/task.py', 'content_base64' => base64_encode('print(1)')]],
            'network' => ['egress_allow' => ['pypi.org:443']],
            'metadata' => ['agent' => 'coder'],
        ]);

        $this->assertSame($warm->id, $data['id']);
        $this->assertTrue($data['from_pool']);
        $this->assertSame('running', $data['status']);
        $this->assertSame(['agent' => 'coder'], $data['metadata']);
        $this->assertSame(1, $pool->fresh()->hits);

        // Labels switched, egress policy for the claimed pod, env + files injected after the claim
        $pod = $this->podOf($warm->id);
        $this->assertSame('claimed', $pod['metadata']['labels']['runr.dev/sandbox-state']);
        $this->assertArrayNotHasKey('runr.dev/sandbox-pool', $pod['metadata']['labels']);
        $this->assertArrayHasKey('runr-sbx-default/sbx-'.strtolower($warm->id).'-egress', $this->k8sPolicies);

        // The warm pod's spec predates the env: it comes as the env file (mode 0600) over the exec
        // channel; the Secret sbx-<id>-env holds it in the cluster
        $inject = $this->exec->callsContaining('exec tar -x -m -o -f - -C');
        $entries = collect(TarReader::entries($inject[0]['stdin']))->keyBy('name');
        $this->assertStringContainsString("export TOKEN='sk-pool-secret'", $entries['home/sandbox/.runr/env.sh']['content']);
        $this->assertSame(0600, $entries['home/sandbox/.runr/env.sh']['mode']);
        $this->assertSame(['TOKEN' => base64_encode('sk-pool-secret')], $this->k8sSecrets['runr-sbx-default/sbx-'.strtolower($warm->id).'-env']['data']);
        $this->assertStringNotContainsString('sk-pool-secret', json_encode($pod['spec']));
        $this->assertSame('print(1)', $entries['workspace/task.py']['content']);

        // No new pod for this request; the pool refill was queued
        $this->assertCount(1, $this->k8sPods);
        Queue::assertPushed(RefillSandboxPoolsJob::class, fn ($job) => $job->poolId === $pool->id);
    }

    public function test_a_spec_without_warm_pod_is_a_miss_and_starts_cold(): void
    {
        Queue::fake();
        $pool = $this->pool(['size' => 1]);

        $data = $this->createSandbox(['runtime' => 'python']);

        $this->assertFalse($data['from_pool']);
        $this->assertSame(1, $pool->fresh()->misses);
        $this->assertSame(0, $pool->fresh()->hits);
        Queue::assertPushed(RefillSandboxPoolsJob::class);
    }

    public function test_other_specs_never_claim_warm_pods(): void
    {
        Queue::fake();
        $pool = $this->pool(['size' => 1]);
        $this->refill();
        $this->refill();

        foreach ([
            ['memory' => 1024],
            ['cpu' => '1'],
            ['read_only_root' => false],
            ['pids_limit' => 64],
            ['sandbox_class' => 'gvisor'],
            ['image' => 'ghcr.io/brf-tech/exec-python:3.14'],
        ] as $input) {
            $data = $this->createSandbox($input);
            $this->assertFalse($data['from_pool'], json_encode($input));
        }

        $this->assertSame('warm', Sandbox::where('pool_id', $pool->id)->sole()->status);
        $this->assertSame(0, $pool->fresh()->hits);
    }

    public function test_a_warm_pod_is_claimed_once(): void
    {
        Queue::fake();
        $pool = $this->pool(['size' => 1]);
        $this->refill();
        $this->refill();

        $first = $this->createSandbox();
        $second = $this->createSandbox();

        $this->assertTrue($first['from_pool']);
        $this->assertFalse($second['from_pool']);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(1, $pool->fresh()->hits);
        $this->assertSame(1, $pool->fresh()->misses);
    }

    public function test_lowering_the_size_removes_surplus_pods(): void
    {
        $pool = $this->pool(['size' => 3]);
        $this->refill();
        $this->refill();

        $pool->update(['size' => 1]);
        $this->refill();

        $this->assertSame(1, Sandbox::where('pool_id', $pool->id)->whereIn('status', Sandbox::POOL_STATUSES)->count());
        $this->assertCount(1, $this->k8sPods);
    }

    public function test_disabled_pools_drain_and_old_or_stale_pods_are_replaced(): void
    {
        $pool = $this->pool(['size' => 1]);
        $this->refill();
        $this->refill();
        $old = Sandbox::where('pool_id', $pool->id)->sole();

        // Older than the pool max age → replaced
        Sandbox::whereKey($old->id)->update(['created_at' => now()->subHours(2)]);
        $this->refill();
        $this->assertSame('terminated', $old->fresh()->status);
        $this->assertSame(1, Sandbox::where('pool_id', $pool->id)->whereIn('status', Sandbox::POOL_STATUSES)->count());

        // The region's default class changes → stale pods are replaced by pods of the new class
        $this->refill();
        SandboxRegionSetting::create(['knative_region_id' => $this->region->id, 'default_class' => 'gvisor']);
        $this->refill();
        $this->refill();
        $this->assertSame(['gvisor'], Sandbox::where('pool_id', $pool->id)->whereIn('status', Sandbox::POOL_STATUSES)->pluck('sandbox_class')->unique()->values()->all());

        // Disabled → drained
        $pool->update(['is_enabled' => false]);
        $this->refill();
        $this->assertSame(0, Sandbox::where('pool_id', $pool->id)->whereIn('status', Sandbox::POOL_STATUSES)->count());
        $this->assertSame([], $this->k8sPods);
    }

    public function test_a_warm_pod_that_cannot_start_is_recorded_on_the_pool(): void
    {
        $this->podStartState = 'image_error';
        $pool = $this->pool(['size' => 1]);

        $this->refill();
        $this->refill();

        $this->assertStringContainsString('ErrImagePull', (string) $pool->fresh()->last_error);
        $this->assertTrue(Sandbox::where('pool_id', $pool->id)->where('status', 'failed')->exists());
        // A replacement is started right away
        $this->assertSame(1, Sandbox::where('pool_id', $pool->id)->where('status', 'warming')->count());
    }

    public function test_pool_api(): void
    {
        Queue::fake();

        $created = $this->postJson('/api/sandbox-pools', ['region' => $this->region->id, 'runtime' => 'python', 'size' => 2])
            ->assertCreated()
            ->assertJsonPath('data.runtime_version', '3.14')
            ->assertJsonPath('data.size', 2)
            ->assertJsonPath('data.cpu_m', 500)
            ->json('data');
        Queue::assertPushed(RefillSandboxPoolsJob::class, fn ($job) => $job->poolId === $created['id']);

        $this->getJson('/api/sandbox-pools')->assertOk()->assertJsonPath('data.0.id', $created['id'])->assertJsonPath('data.0.region.name', 'eu-1');
        $this->putJson("/api/sandbox-pools/{$created['id']}", ['size' => 4])->assertOk()->assertJsonPath('data.size', 4);
        $this->putJson("/api/sandbox-pools/{$created['id']}", ['size' => 11])->assertStatus(422)->assertJsonPath('reason', 'quota_pool');

        $this->postJson('/api/sandbox-pools', ['region' => $this->region->id, 'runtime' => 'cobol', 'size' => 1])->assertStatus(422);
        $this->postJson('/api/sandbox-pools', ['region' => $this->region->id, 'runtime' => 'python', 'version' => '0.1', 'size' => 1])->assertStatus(422);
        $this->postJson('/api/sandbox-pools', ['region' => $this->region->id, 'runtime' => 'python', 'size' => 1, 'sandbox_class' => 'runc'])
            ->assertStatus(422)->assertJsonPath('reason', 'class_unavailable');

        $this->deleteJson("/api/sandbox-pools/{$created['id']}")->assertNoContent();
        $this->assertNull(SandboxPool::find($created['id']));
    }

    public function test_metrics_count_hits_misses_and_start_times(): void
    {
        Queue::fake();
        $pool = $this->pool(['size' => 1]);
        $this->refill();
        $this->refill();
        $this->createSandbox(); // hit
        $this->createSandbox(); // miss → cold

        Sandbox::where('from_pool', true)->update(['start_ms' => 180]);
        Sandbox::where('from_pool', false)->whereNull('pool_id')->update(['start_ms' => 2200]);

        $summary = app(SandboxMetrics::class)->summary(null);
        $this->assertSame(['hits' => 1, 'misses' => 1, 'hit_rate' => 0.5], $summary['pool']);
        $this->assertSame(1, $summary['start_time']['pool_hit']['count']);
        $this->assertSame(180, $summary['start_time']['pool_hit']['sum_ms']);
        $buckets = collect($summary['start_time']['cold']['buckets'])->pluck('count', 'le_ms');
        $this->assertSame(0, $buckets[1000]);
        $this->assertSame(1, $buckets[2500]);
        $this->assertSame(1, $buckets['']);

        $this->getJson('/api/sandbox-metrics?window=24h')->assertOk()->assertJsonPath('data.pool.hits', 1);

        $text = app(SandboxMetrics::class)->prometheus();
        $this->assertStringContainsString('runr_sandbox_start_seconds_bucket{region="'.$this->region->id.'",pool_hit="true",le="0.25"} 1', $text);
        $this->assertStringContainsString('result="miss"} 1', $text);
    }
}
