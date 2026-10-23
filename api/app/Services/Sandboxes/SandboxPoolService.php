<?php

namespace App\Services\Sandboxes;

use App\Jobs\Sandboxes\PromoteWarmSandboxesJob;
use App\Jobs\Sandboxes\RefillSandboxPoolsJob;
use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Sandbox;
use App\Models\SandboxPool;
use App\Models\SandboxRegionSetting;
use App\Services\Residency\RegionPolicy;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Warm pools (docs/sandbox-api.md → Warm pool). A pool keeps `size` generic sandboxes of one
 * runtime/version, size and class started in its organization's namespace of a region. A create
 * request with the same spec (no custom image, default PID limit and root filesystem mode) claims
 * the oldest warm pod — an atomic warm → claiming update of its row, so two requests never get
 * the same pod — then its labels, egress policy, env file and files are applied and it runs. The
 * pool is refilled asynchronously (RefillSandboxPoolsJob, also every minute); a pod the refill
 * started becomes warm as soon as it runs (PromoteWarmSandboxesJob), not at the next refill. Warm
 * pods older than sandbox.pool.max_age_seconds are replaced so every claimed pod keeps its full
 * lifetime.
 *
 * Pool hits and misses are counted on the pool (a miss: a pool exists for the spec but no warm
 * pod was free); the sandboxes' start_ms with from_pool feed the start-time histograms.
 */
class SandboxPoolService
{
    /** At most this many pods started per pool and refill run */
    private const MAX_STARTS_PER_RUN = 5;

    public function __construct(
        protected SandboxClassResolver $classes,
        protected SandboxQuotaService $quotas,
        protected SandboxNamespaces $namespaces,
    ) {}

    /**
     * Claim a warm pod for $spec (call inside the admission lock). Null: no pool / no free pod.
     */
    public function claim(SandboxSpec $spec, ?string $tenantId, ?string $userId): ?Sandbox
    {
        $defaults = (array) config('sandbox.defaults');
        if ($spec->poolKey() === null
            || $spec->readOnlyRoot !== (bool) $defaults['read_only_root']
            || $spec->pidsLimit !== (int) $defaults['pids_limit']) {
            return null;
        }

        $pools = $this->scope(SandboxPool::query(), $tenantId)
            ->where('knative_region_id', $spec->region->id)
            ->where('runtime', $spec->runtime)
            ->where('runtime_version', $spec->runtimeVersion)
            ->where('cpu_m', $spec->cpuM)
            ->where('memory_mb', $spec->memoryMb)
            ->where('disk_mb', $spec->diskMb)
            ->where('is_enabled', true)
            ->get();

        if ($pools->isEmpty()) {
            return null;
        }

        $candidates = Sandbox::query()
            ->whereIn('pool_id', $pools->pluck('id'))
            ->where('status', Sandbox::STATUS_WARM)
            ->where('sandbox_class', $spec->sandboxClass)
            ->where('image', $spec->image)
            ->orderBy('created_at')
            ->limit(5)
            ->get();

        foreach ($candidates as $candidate) {
            $won = Sandbox::query()->whereKey($candidate->id)->where('status', Sandbox::STATUS_WARM)
                ->update(['status' => Sandbox::STATUS_CLAIMING, 'updated_at' => now()]) === 1;
            if (! $won) {
                continue;
            }

            $sandbox = Sandbox::findOrFail($candidate->id);
            $sandbox->fill([
                'created_by' => $userId,
                'from_pool' => true,
                'network' => $spec->network,
                'env' => $spec->env,
                'metadata' => $spec->metadata ?: null,
                'ttl_seconds' => $spec->ttlSeconds,
                'idle_timeout_seconds' => $spec->idleTimeoutSeconds,
                'expires_at' => now()->addSeconds($spec->ttlSeconds),
                'last_activity_at' => now(),
            ])->save();

            SandboxPool::query()->whereKey($sandbox->pool_id)->increment('hits');

            return $sandbox;
        }

        // A pool for this spec, but no free pod: a miss
        $pool = $pools->first(fn (SandboxPool $p) => $p->sandbox_class === null || $p->sandbox_class === $spec->sandboxClass);
        if ($pool) {
            SandboxPool::query()->whereKey($pool->id)->increment('misses');
            $this->dispatchRefill($pool->id);
        }

        return null;
    }

    public function dispatchRefill(?string $poolId = null): void
    {
        try {
            RefillSandboxPoolsJob::dispatch($poolId);
        } catch (\Throwable $e) {
            Log::warning('sandbox pool refill not dispatched', ['pool' => $poolId, 'error' => $e->getMessage()]);
        }
    }

    /** Refill every enabled pool (or one); drain disabled ones. Returns pods started. */
    public function refill(?string $poolId = null): int
    {
        $started = 0;
        $pools = SandboxPool::query()->when($poolId, fn ($q) => $q->whereKey($poolId))->get();

        foreach ($pools as $pool) {
            try {
                $started += SandboxContext::run($pool->tenant_id, fn () => $this->refillPool($pool));
            } catch (\Throwable $e) {
                Log::warning('sandbox pool refill failed', ['pool' => $pool->id, 'error' => $e->getMessage()]);
                $pool->update(['last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            }
        }

        return $started;
    }

    /** One pool, in its organization's context. */
    protected function refillPool(SandboxPool $pool): int
    {
        $service = app(SandboxService::class);
        $members = $pool->warmSandboxes()->orderBy('created_at')->get();

        $region = KnativeRegion::find($pool->knative_region_id);
        $usable = $region && $region->is_active && $region->kubeconfig && SandboxRegionSetting::forRegion($region->id)->enabled
            // data residency of the organization may have changed since the pool was created
            && app(RegionPolicy::class)->allows($region, RegionPolicy::SANDBOX_CREATE, $pool->tenant_id);

        if (! $pool->is_enabled || ! $usable) {
            foreach ($members as $member) {
                $service->terminate($member);
            }

            return 0;
        }

        // Spec of the pool's pods now: class and image may have changed since they were started
        $class = $this->poolClass($pool, $region);
        $image = $this->poolImage($pool, $region);

        $maxAge = (int) config('sandbox.pool.max_age_seconds', 3600);
        $startTimeout = (int) config('sandbox.start_timeout_seconds', 120) * 2;
        $k8s = $service->k8s($region);
        $live = [];
        $error = null;

        foreach ($members as $member) {
            $stale = $member->sandbox_class !== $class || $member->image !== $image
                || $member->created_at->lt(now()->subSeconds($maxAge));

            if ($member->status === Sandbox::STATUS_WARMING) {
                $state = SandboxService::podState($k8s->getResourceStatus('v1', 'Pod', $member->pod_name, $member->namespace));
                if ($state === null) {
                    // the runtime keeps failing to create the pod sandbox: the pod will not start
                    $state = $service->startFailure($k8s, $member->namespace, $member->pod_name);
                }
                if ($state === true) {
                    $member->update(['status' => Sandbox::STATUS_WARM]);
                } elseif (is_string($state) || $member->created_at->lt(now()->subSeconds($startTimeout))) {
                    $error = is_string($state) ? $state : __('sandboxes.pool_start_timeout');
                    $service->terminate($member, Sandbox::END_FAILED, is_string($state) ? $state : null);

                    continue;
                }
            }

            if ($stale) {
                $service->terminate($member);

                continue;
            }
            $live[] = $member;
        }

        // Too many (size lowered): the newest go first
        $surplus = count($live) - $pool->size;
        for ($i = 0; $i < $surplus; $i++) {
            $service->terminate(array_pop($live));
        }

        $deficit = min($pool->size - count($live), self::MAX_STARTS_PER_RUN);
        $started = [];
        for ($i = 0; $i < $deficit; $i++) {
            try {
                $this->quotas->checkRegionQuota($pool->tenant_id, $region, $pool->cpu_m, $pool->memory_mb, $class);
                $this->quotas->checkRegionCapacity($region, $pool->cpu_m, $pool->memory_mb, $class);
            } catch (SandboxException $e) {
                $error = $e->getMessage();
                break;
            }

            $started[] = $this->startWarmPod($pool, $region, $class, $image)->id;
        }

        // The last problem stays visible on the pool until a run goes through without one
        $pool->update(['last_error' => $error !== null ? mb_substr($error, 0, 1000) : null]);

        if ($started !== []) {
            $this->dispatchPromote($pool->id, $started);
        }

        return count($started);
    }

    /** Warm the pods a refill just started as soon as they run (PromoteWarmSandboxesJob). */
    public function dispatchPromote(string $poolId, array $sandboxIds): void
    {
        try {
            PromoteWarmSandboxesJob::dispatch($poolId, array_values($sandboxIds));
        } catch (\Throwable $e) {
            Log::warning('sandbox pool promotion not dispatched', ['pool' => $poolId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Pods of the pool that a refill started: warming → warm as soon as each pod runs, read on the
     * start-poll schedule (SandboxService::startPollDelayUs()) for up to $wait seconds
     * (sandbox.pool.promote_wait_seconds). A pod that cannot start, or still starts after the wait,
     * stays warming: the next refill replaces it (recording why) or warms it. Call in the pool's
     * organization context. Returns the pods warmed.
     *
     * @param  list<string>  $sandboxIds
     */
    public function promote(SandboxPool $pool, array $sandboxIds, ?int $wait = null): int
    {
        $pending = Sandbox::query()->whereIn('id', $sandboxIds)->where('pool_id', $pool->id)
            ->where('status', Sandbox::STATUS_WARMING)->get()->keyBy('id');
        $region = KnativeRegion::find($pool->knative_region_id);
        if ($pending->isEmpty() || ! $region || ! $region->kubeconfig) {
            return 0;
        }

        $k8s = app(SandboxService::class)->k8s($region);
        $deadline = microtime(true) + max(0, $wait ?? (int) config('sandbox.pool.promote_wait_seconds', 60));
        $promoted = 0;
        $polls = 0;

        while (true) {
            foreach ($pending as $id => $member) {
                try {
                    $state = SandboxService::podState($k8s->getResourceStatus('v1', 'Pod', $member->pod_name, $member->namespace));
                } catch (\Throwable) {
                    continue;   // the cluster did not answer: read it again
                }
                if ($state === true) {
                    $promoted += Sandbox::query()->whereKey($id)->where('status', Sandbox::STATUS_WARMING)
                        ->update(['status' => Sandbox::STATUS_WARM, 'updated_at' => now()]);
                    $pending->forget($id);
                } elseif (is_string($state)) {
                    $pending->forget($id);   // the refill replaces it and records the reason
                }
            }

            $left = (int) (($deadline - microtime(true)) * 1_000_000);
            if ($pending->isEmpty() || $left <= 0) {
                return $promoted;
            }
            Sleep::usleep(min(SandboxService::startPollDelayUs($polls++), $left));
        }
    }

    /** Create one warm pod of the pool. */
    public function startWarmPod(SandboxPool $pool, KnativeRegion $region, string $class, string $image): Sandbox
    {
        $service = app(SandboxService::class);
        $defaults = (array) config('sandbox.defaults');
        $id = (string) Str::ulid();

        $sandbox = Sandbox::create([
            'id' => $id,
            'tenant_id' => $pool->tenant_id,
            'knative_region_id' => $region->id,
            'namespace' => $this->namespaces->nameFor($pool->tenant_id),
            'pod_name' => SandboxManifests::podName($id),
            'status' => Sandbox::STATUS_WARMING,
            'pool_id' => $pool->id,
            'runtime' => $pool->runtime,
            'runtime_version' => $pool->runtime_version,
            'image' => $image,
            'sandbox_class' => $class,
            'cpu_m' => $pool->cpu_m,
            'memory_mb' => $pool->memory_mb,
            'disk_mb' => $pool->disk_mb,
            'pids_limit' => (int) $defaults['pids_limit'],
            'uid' => $service->allocateUid($region->id),
            'read_only_root' => (bool) $defaults['read_only_root'],
            'network' => ['mode' => 'none', 'egress_allow' => [], 'rules' => []],
            'ttl_seconds' => (int) $defaults['ttl_seconds'],
            'idle_timeout_seconds' => (int) $defaults['idle_timeout_seconds'],
        ]);

        try {
            $service->createPod($sandbox, $region);
        } catch (SandboxException $e) {
            $sandbox->update(['status' => Sandbox::STATUS_FAILED, 'ended_at' => now(), 'end_reason' => Sandbox::END_FAILED, 'error' => $e->getMessage()]);
            throw $e;
        }

        return $sandbox;
    }

    /** Remove a pool and its warm pods. */
    public function delete(SandboxPool $pool): void
    {
        SandboxContext::run($pool->tenant_id, function () use ($pool) {
            $service = app(SandboxService::class);
            foreach ($pool->warmSandboxes()->get() as $member) {
                $service->terminate($member);
            }
        });
        $pool->delete();
    }

    /** Class of the pool's pods: its own when still allowed, else the region's default. */
    public function poolClass(SandboxPool $pool, KnativeRegion $region): string
    {
        $tenantAllowed = $this->quotas->limits($pool->tenant_id)['allowed_classes'];

        return $this->classes->resolve($pool->sandbox_class, $region, SandboxRegionSetting::forRegion($region->id), $tenantAllowed,
            app(RuntimeRegistry::class)->manifest($pool->runtime));
    }

    /** Image of the pool's runtime version in the region. */
    public function poolImage(SandboxPool $pool, KnativeRegion $region): string
    {
        $runtimes = app(RuntimeRegistry::class);
        $manifest = $runtimes->require($pool->runtime);
        $executor = ExecutorImage::resolve($pool->runtime, $pool->runtime_version);
        if (! $executor) {
            throw new SandboxException(__('sandboxes.version_unknown', ['runtime' => $pool->runtime, 'version' => (string) $pool->runtime_version]), 422, 'runtime_unavailable');
        }

        return app(RuntimePatches::class)->forSandbox($executor, $runtimes->versionImage($manifest, $executor, $region));
    }

    /** API shape of a pool. */
    public function describe(SandboxPool $pool, ?KnativeRegion $region = null): array
    {
        $counts = Sandbox::query()->where('pool_id', $pool->id)->whereIn('status', Sandbox::POOL_STATUSES)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'id' => $pool->id,
            'region' => ['id' => $pool->knative_region_id, 'name' => $region?->name],
            'runtime' => $pool->runtime,
            'runtime_version' => $pool->runtime_version,
            'sandbox_class' => $pool->sandbox_class,
            'cpu_m' => $pool->cpu_m,
            'memory_mb' => $pool->memory_mb,
            'disk_mb' => $pool->disk_mb,
            'size' => $pool->size,
            'is_enabled' => $pool->is_enabled,
            'warm' => (int) ($counts[Sandbox::STATUS_WARM] ?? 0),
            'starting' => (int) ($counts[Sandbox::STATUS_WARMING] ?? 0),
            'hits' => $pool->hits,
            'misses' => $pool->misses,
            'last_error' => $pool->last_error,
            'tenant_id' => $pool->tenant_id,
            'created_at' => $pool->created_at?->toIso8601String(),
        ];
    }

    public function scope($query, ?string $tenantId)
    {
        return $query->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId));
    }
}
