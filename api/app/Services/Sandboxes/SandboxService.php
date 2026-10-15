<?php

namespace App\Services\Sandboxes;

use App\Jobs\Sandboxes\StartSandboxJob;
use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Sandbox;
use App\Models\SandboxProcess;
use App\Models\SandboxRegionSetting;
use App\Services\KubernetesClient;
use App\Services\Residency\RegionPolicy;
use App\Services\Runtimes\RuntimeImagePendingException;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Sandboxes\Exec\ExecResult;
use App\Services\Sandboxes\Exec\ExecScript;
use App\Services\Sandboxes\Exec\ExecTransport;
use App\Services\Sandboxes\Exec\ExecTransportException;
use App\Services\Sandboxes\Exec\PodTarget;
use App\Services\Sandboxes\Tar\TarWriter;
use App\Support\RequestTime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sandbox lifecycle: create (warm pool claim or cold start), start, terminate, TTL, idle timer,
 * pause / resume, network, and the exec primitive every other feature (files, processes, code
 * runs) builds on.
 */
class SandboxService
{
    public function __construct(
        protected RuntimeRegistry $runtimes,
        protected SandboxRegions $regions,
        protected SandboxClassResolver $classes,
        protected SandboxQuotaService $quotas,
        protected SandboxNetwork $network,
        protected SandboxNamespaces $namespaces,
        protected SandboxPoolService $pools,
        protected ExecTransport $transport,
    ) {}

    // ── Create ──────────────────────────────────────────────────────────────────────────────

    /**
     * Resolve a validated create request into a spec.
     *
     * @throws SandboxException
     */
    public function spec(array $input, ?string $tenantId): SandboxSpec
    {
        $region = $this->regions->resolve($input['region'] ?? null);
        // Data residency (docs/supply-chain.md): only the organization's allowed regions (422)
        app(RegionPolicy::class)->authorize($region, RegionPolicy::SANDBOX_CREATE, $tenantId, 'region');
        $settings = SandboxRegionSetting::forRegion($region->id);
        $defaults = (array) config('sandbox.defaults');

        // Image: a runtime of the catalog (default python), or any image
        $image = $input['image'] ?? null;
        $runtime = null;
        $version = null;
        $manifest = null;
        if ($image !== null && $image !== '') {
            if (! config('sandbox.allow_custom_images', true)) {
                $this->invalid('image', __('sandboxes.custom_images_disabled'), 'runtime_unavailable');
            }
        } else {
            [$runtime, $version, $image, $manifest] = $this->runtimeImage($input['runtime'] ?? config('sandbox.default_runtime', 'python'), $input['version'] ?? null, $region);
        }

        $cpu = Sizes::cpuMillicores($input['cpu'] ?? null) ?? (int) $defaults['cpu_m'];
        $mem = Sizes::mebibytes($input['memory'] ?? null) ?? (int) $defaults['memory_mb'];
        $disk = Sizes::mebibytes($input['disk'] ?? null) ?? (int) $defaults['disk_mb'];
        if (array_key_exists('cpu', $input) && $input['cpu'] !== null && Sizes::cpuMillicores($input['cpu']) === null) {
            $this->invalid('cpu', __('sandboxes.cpu_invalid'));
        }
        if (array_key_exists('memory', $input) && $input['memory'] !== null && Sizes::mebibytes($input['memory']) === null) {
            $this->invalid('memory', __('sandboxes.memory_invalid'));
        }
        if (array_key_exists('disk', $input) && $input['disk'] !== null && Sizes::mebibytes($input['disk']) === null) {
            $this->invalid('disk', __('sandboxes.disk_invalid'));
        }
        if ($cpu < 50 || $cpu > $settings->maxCpuM()) {
            $this->invalid('cpu', __('sandboxes.cpu_range', ['max' => $settings->maxCpuM()]));
        }
        if ($mem < 64 || $mem > $settings->maxMemoryMb()) {
            $this->invalid('memory', __('sandboxes.memory_range', ['max' => $settings->maxMemoryMb()]));
        }
        if ($disk < 64 || $disk > $settings->maxDiskMb()) {
            $this->invalid('disk', __('sandboxes.disk_range', ['max' => $settings->maxDiskMb()]));
        }

        $pids = (int) ($input['pids_limit'] ?? $defaults['pids_limit']);
        $pids = max(16, min($pids, (int) config('sandbox.limits.max_pids', 4096)));

        $maxTtl = $settings->maxTtl();
        $ttl = (int) ($input['ttl_seconds'] ?? $settings->defaultTtl());
        if ($ttl < 10 || $ttl > $maxTtl) {
            $this->invalid('ttl_seconds', __('sandboxes.ttl_range', ['max' => $maxTtl]));
        }
        $idle = (int) ($input['idle_timeout'] ?? $input['idle_timeout_seconds'] ?? $defaults['idle_timeout_seconds']);
        $idle = max(0, min($idle, $maxTtl));

        $tenantAllowed = $this->quotas->limits($tenantId)['allowed_classes'];
        $class = $this->classes->resolve($input['sandbox_class'] ?? null, $region, $settings, $tenantAllowed, $manifest);

        $network = $this->network->parse($input['network'] ?? null);

        $env = [];
        foreach ((array) ($input['env'] ?? []) as $key => $value) {
            ExecScript::assertEnvKey((string) $key);
            $env[(string) $key] = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        }

        $files = $this->files((array) ($input['files'] ?? []));

        return new SandboxSpec(
            region: $region,
            runtime: $runtime,
            runtimeVersion: $version,
            image: $image,
            sandboxClass: $class,
            cpuM: $cpu,
            memoryMb: $mem,
            diskMb: $disk,
            pidsLimit: $pids,
            ttlSeconds: $ttl,
            idleTimeoutSeconds: $idle,
            network: $network,
            env: $env,
            files: $files,
            metadata: (array) ($input['metadata'] ?? []),
            readOnlyRoot: (bool) ($input['read_only_root'] ?? $defaults['read_only_root']),
            customImage: $runtime === null,
            maxTtlSeconds: $maxTtl,
        );
    }

    /**
     * Create a sandbox: quota admission, then a warm pod of a matching pool or a new pod. With
     * $wait the call returns once the sandbox runs (or fails, or start_timeout passes — the start
     * then finishes in the background); without it right after the pod was created.
     *
     * @throws SandboxException
     */
    public function create(array $input, ?string $tenantId, ?string $userId, bool $platformAdmin = false): Sandbox
    {
        $requestStart = hrtime(true);
        $spec = $this->spec($input, $tenantId);
        $wait = (bool) ($input['wait'] ?? true);

        $sandbox = $this->quotas->lock($tenantId, function () use ($spec, $tenantId, $userId, $platformAdmin) {
            $this->quotas->check($tenantId, $spec->region, $spec->cpuM, $spec->memoryMb, $spec->sandboxClass, $platformAdmin);

            // Warm pool: a pod of the same spec, handed over as it is (env + files follow)
            if (($claimed = $this->pools->claim($spec, $tenantId, $userId)) !== null) {
                return $claimed;
            }

            $id = (string) Str::ulid();

            return Sandbox::create([
                'id' => $id,
                'tenant_id' => $tenantId,
                'created_by' => $userId,
                'knative_region_id' => $spec->region->id,
                'namespace' => $this->namespaces->nameFor($tenantId),
                'pod_name' => SandboxManifests::podName($id),
                'status' => Sandbox::STATUS_PENDING,
                'runtime' => $spec->runtime,
                'runtime_version' => $spec->runtimeVersion,
                'image' => $spec->image,
                'sandbox_class' => $spec->sandboxClass,
                'cpu_m' => $spec->cpuM,
                'memory_mb' => $spec->memoryMb,
                'disk_mb' => $spec->diskMb,
                'pids_limit' => $spec->pidsLimit,
                'uid' => $this->allocateUid($spec->region->id),
                'read_only_root' => $spec->readOnlyRoot,
                'network' => $spec->network,
                'env' => $spec->env,
                'metadata' => $spec->metadata ?: null,
                'ttl_seconds' => $spec->ttlSeconds,
                'idle_timeout_seconds' => $spec->idleTimeoutSeconds,
                'expires_at' => now()->addSeconds($spec->ttlSeconds),
                'last_activity_at' => now(),
            ]);
        });

        SandboxLog::record($sandbox, SandboxLog::CREATE, null, [
            'runtime' => $sandbox->runtime,
            'version' => $sandbox->runtime_version,
            'image' => $sandbox->image,
            'sandbox_class' => $sandbox->sandbox_class,
            'region' => $spec->region->name,
            'from_pool' => $sandbox->from_pool,
            'network' => $sandbox->network['mode'] ?? 'none',
        ], $userId);

        $this->stashFiles($sandbox, $spec->files);

        try {
            if ($sandbox->from_pool) {
                $this->pools->dispatchRefill($sandbox->pool_id);
                $k8s = $this->k8s($spec->region);
                $k8s->mergePatch('v1', 'Pod', $sandbox->pod_name, $sandbox->namespace, SandboxManifests::claimLabels());
                $this->applyEgressPolicy($k8s, $sandbox);
                $this->applyEnvSecret($k8s, $sandbox);
                $this->finishStart($sandbox, $spec->region, $requestStart);

                return $sandbox->refresh();
            }

            $this->createPod($sandbox, $spec->region);
        } catch (SandboxException $e) {
            $this->markFailed($sandbox, $spec->region, $e->getMessage());
            throw new SandboxException($e->getMessage(), $e->status, $e->reason, $e->extra + ['sandbox_id' => $sandbox->id]);
        }

        if (! $wait) {
            StartSandboxJob::dispatch($sandbox->id, (int) ((hrtime(true) - $requestStart) / 1_000_000));

            return $sandbox->refresh();
        }

        $timeout = max(5, min((int) ($input['start_timeout'] ?? config('sandbox.start_timeout_seconds', 120)), 600));
        RequestTime::allow($timeout);
        $state = $this->waitRunning($sandbox, $spec->region, $timeout);

        if ($state === true) {
            $this->finishStart($sandbox, $spec->region, $requestStart);
        } elseif ($state === null) {
            // Still starting (image pull, Kata boot): the job takes over
            StartSandboxJob::dispatch($sandbox->id, (int) ((hrtime(true) - $requestStart) / 1_000_000));
        } else {
            $this->markFailed($sandbox, $spec->region, $state);
        }

        return $sandbox->refresh();
    }

    /** Pod + egress policy of a new (non-pool) sandbox. */
    public function createPod(Sandbox $sandbox, KnativeRegion $region): void
    {
        $k8s = $this->k8s($region);
        $this->namespaces->ensure($k8s, $region, $sandbox->namespace, $sandbox->tenant_id);

        $this->applyEgressPolicy($k8s, $sandbox);
        $this->applyEnvSecret($k8s, $sandbox);

        $settings = SandboxRegionSetting::forRegion($region->id);
        $deadline = $settings->maxTtl() + ($sandbox->pool_id ? (int) config('sandbox.pool.max_age_seconds', 3600) : 0) + (int) config('sandbox.deadline_grace_seconds', 300);

        try {
            $created = $k8s->createResource(SandboxManifests::pod($sandbox, $region, $deadline), $sandbox->namespace);
        } catch (\Throwable $e) {
            throw SandboxException::cluster($e->getMessage());
        }
        if (! $created) {
            throw SandboxException::cluster(__('sandboxes.pod_failed'));
        }
    }

    /**
     * Wait for the pod: true when it runs, null when $timeout passed while it is still starting,
     * or the reason it cannot start (image pull error, …).
     */
    public function waitRunning(Sandbox $sandbox, KnativeRegion $region, int $timeout): bool|string|null
    {
        $k8s = $this->k8s($region);
        $deadline = microtime(true) + $timeout;
        $poll = 200_000;

        do {
            $state = self::podState($k8s->getResourceStatus('v1', 'Pod', $sandbox->pod_name, $sandbox->namespace));
            if ($state === true || is_string($state)) {
                return $state;
            }
            usleep($poll);
            $poll = min(1_000_000, (int) ($poll * 1.5));
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * Pod readiness from its status: true running, string why it will not run, null still starting.
     */
    public static function podState(?array $pod): bool|string|null
    {
        if ($pod === null) {
            return __('sandboxes.pod_gone');
        }

        $phase = $pod['status']['phase'] ?? 'Pending';
        if (in_array($phase, ['Failed', 'Succeeded'], true)) {
            return trim(($pod['status']['reason'] ?? $phase).': '.($pod['status']['message'] ?? ''), ': ');
        }

        foreach ($pod['status']['containerStatuses'] ?? [] as $cs) {
            $waiting = $cs['state']['waiting'] ?? null;
            if ($waiting && in_array($waiting['reason'] ?? '', ['ErrImagePull', 'ImagePullBackOff', 'InvalidImageName', 'CreateContainerConfigError', 'CreateContainerError', 'RunContainerError', 'CrashLoopBackOff', 'ErrImageNeverPull'], true)) {
                return trim(($waiting['reason'] ?? '').': '.($waiting['message'] ?? ''), ': ');
            }
            if (isset($cs['state']['terminated'])) {
                $t = $cs['state']['terminated'];

                return trim('Terminated: '.($t['reason'] ?? '').' '.($t['message'] ?? ''));
            }
            if ($phase === 'Running' && isset($cs['state']['running'])) {
                return true;
            }
        }

        return null;
    }

    /**
     * The pod runs: write the files (and, for a claimed warm pod, the env file), then the sandbox is running.
     *
     * @throws SandboxException
     */
    public function finishStart(Sandbox $sandbox, KnativeRegion $region, int|float|null $requestStartHr = null, ?int $elapsedMs = null): void
    {
        $files = $this->takeFiles($sandbox);
        $tar = new TarWriter;
        $dirs = [];

        // A warm pod was started before its env was known: the env file carries it (a cold pod
        // has it from the Secret sbx-<id>-env through secretKeyRef)
        if ($sandbox->from_pool && ($sandbox->env ?? []) !== []) {
            $tar->addFile(ltrim(SandboxManifests::ENV_FILE, '/'), ExecScript::envFile($sandbox->env), 0600);
            $dirs[] = dirname(SandboxManifests::ENV_FILE);
        }
        foreach ($files as $file) {
            $tar->addFile(ltrim($file['path'], '/'), $file['content'], $file['mode']);
            $dirs[] = dirname($file['path']);
        }

        if ($dirs !== []) {
            $result = $this->rawExec($sandbox, $region, ExecScript::extractTarAt('/', $dirs), $tar->bytes(), 120);
            if (! $result->ok()) {
                throw new SandboxException(__('sandboxes.files_failed', ['detail' => trim($result->stderr) ?: "exit {$result->exitCode}"]), 502, 'files_failed');
            }
        }

        $now = now();
        $startMs = $elapsedMs ?? ($requestStartHr !== null ? (int) ((hrtime(true) - $requestStartHr) / 1_000_000) : null);
        $expires = $now->copy()->addSeconds($sandbox->ttl_seconds);

        Sandbox::query()->whereKey($sandbox->id)->whereIn('status', [Sandbox::STATUS_PENDING, Sandbox::STATUS_CLAIMING])->update([
            'status' => Sandbox::STATUS_RUNNING,
            'started_at' => $now,
            'last_activity_at' => $now,
            'expires_at' => $expires,
            'start_ms' => $startMs,
            'updated_at' => $now,
        ]);
        $sandbox->refresh();

        SandboxLog::record($sandbox, SandboxLog::START, null, ['start_ms' => $startMs, 'from_pool' => $sandbox->from_pool, 'files' => count($files)]);
    }

    // ── Lifecycle ───────────────────────────────────────────────────────────────────────────

    /**
     * Stop a sandbox: delete its pod and egress policy. $reason: deleted | ttl | idle | quota |
     * failed | lost. Idempotent for ended sandboxes.
     */
    public function terminate(Sandbox $sandbox, string $reason = Sandbox::END_DELETED, ?string $error = null): Sandbox
    {
        if ($sandbox->isEnded()) {
            return $sandbox;
        }

        $wasPool = in_array($sandbox->status, Sandbox::POOL_STATUSES, true);
        $sandbox->update(['status' => Sandbox::STATUS_TERMINATING]);

        $deleted = true;
        try {
            $region = SandboxContext::region($sandbox->knative_region_id, $sandbox->tenant_id);
            if ($region && $region->kubeconfig) {
                $k8s = $this->k8s($region);
                $deleted = $k8s->deleteResource('v1', 'Pod', $sandbox->pod_name, $sandbox->namespace);
                $k8s->deleteResource('networking.k8s.io/v1', 'NetworkPolicy', SandboxManifests::egressPolicyName($sandbox->id), $sandbox->namespace);
                if (! empty($sandbox->env)) {
                    $k8s->deleteResource('v1', 'Secret', SandboxManifests::envSecretName($sandbox->id), $sandbox->namespace);
                }
            }
        } catch (\Throwable $e) {
            // Region / cluster unreachable: the reaper retries terminating sandboxes
            Log::warning('sandbox pod delete failed', ['sandbox' => $sandbox->id, 'error' => $e->getMessage()]);
            $deleted = false;
        }

        if (! $deleted) {
            $sandbox->update(['error' => $error ?? $sandbox->error, 'end_reason' => $reason]);

            return $sandbox;
        }

        $failed = $reason === Sandbox::END_FAILED;
        $sandbox->update([
            'status' => $failed ? Sandbox::STATUS_FAILED : Sandbox::STATUS_TERMINATED,
            'ended_at' => $sandbox->ended_at ?? now(),
            'end_reason' => $reason,
            'error' => $error ?? $sandbox->error,
            'paused_at' => null,
        ]);

        SandboxProcess::where('sandbox_id', $sandbox->id)->where('status', SandboxProcess::STATUS_RUNNING)
            ->update(['status' => SandboxProcess::STATUS_KILLED, 'ended_at' => now()]);
        $this->forgetFiles($sandbox);

        if (! $wasPool) {
            SandboxLog::record($sandbox, $reason === Sandbox::END_DELETED ? SandboxLog::DELETE : ($failed ? SandboxLog::FAIL : SandboxLog::EXPIRE), $error, ['reason' => $reason]);
        }

        return $sandbox;
    }

    /** A sandbox whose start failed: pod removed, status failed with the reason. */
    public function markFailed(Sandbox $sandbox, ?KnativeRegion $region, string $error): void
    {
        $this->terminate($sandbox->refresh(), Sandbox::END_FAILED, $error);
        if ($sandbox->status !== Sandbox::STATUS_FAILED) {
            // The pod could not be deleted now; the reaper finishes it
            $sandbox->update(['error' => $error]);
        }
    }

    /**
     * New expiry: now + $ttl, never beyond the sandbox's longest life (start + the region's max TTL,
     * which its pod's activeDeadlineSeconds also enforces).
     */
    public function extend(Sandbox $sandbox, int $ttl): Sandbox
    {
        $this->assertAlive($sandbox);

        $max = SandboxRegionSetting::forRegion($sandbox->knative_region_id)->maxTtl();
        $start = $sandbox->started_at ?? $sandbox->created_at;
        $limit = $start->copy()->addSeconds($max);
        if ($ttl < 10 || $ttl > $max) {
            $this->invalid('ttl_seconds', __('sandboxes.ttl_range', ['max' => $max]));
        }

        $expires = now()->addSeconds($ttl);
        if ($expires->greaterThan($limit)) {
            $expires = $limit;
        }

        $sandbox->update(['expires_at' => $expires, 'ttl_seconds' => $ttl]);
        SandboxLog::record($sandbox, SandboxLog::TIMEOUT, null, ['expires_at' => $expires->toIso8601String(), 'capped' => $expires->equalTo($limit)]);

        return $sandbox->refresh();
    }

    public function keepalive(Sandbox $sandbox): Sandbox
    {
        $this->assertAlive($sandbox);
        $sandbox->touchActivity();

        return $sandbox;
    }

    /** Freeze every process (SIGSTOP); exec is refused until resume. TTL keeps running. */
    public function pause(Sandbox $sandbox): Sandbox
    {
        $this->assertRunning($sandbox);
        $region = $this->regionOf($sandbox);
        $this->rawExec($sandbox, $region, ExecScript::signalAll('STOP'), null, 30);
        $sandbox->update(['status' => Sandbox::STATUS_PAUSED, 'paused_at' => now()]);
        SandboxLog::record($sandbox, SandboxLog::PAUSE);

        return $sandbox->refresh();
    }

    public function resume(Sandbox $sandbox): Sandbox
    {
        if ($sandbox->status !== Sandbox::STATUS_PAUSED) {
            throw SandboxException::notRunning($sandbox->status);
        }
        $region = $this->regionOf($sandbox);
        $this->rawExec($sandbox, $region, ExecScript::signalAll('CONT'), null, 30);
        $sandbox->update(['status' => Sandbox::STATUS_RUNNING, 'paused_at' => null, 'last_activity_at' => now()]);
        SandboxLog::record($sandbox, SandboxLog::RESUME);

        return $sandbox->refresh();
    }

    /** Replace the network (default deny / a new allowlist; host names resolved again). */
    public function updateNetwork(Sandbox $sandbox, mixed $input): Sandbox
    {
        $this->assertAlive($sandbox);
        $network = $this->network->parse($input);
        $sandbox->update(['network' => $network]);

        $region = $this->regionOf($sandbox);
        $this->applyEgressPolicy($this->k8s($region), $sandbox);
        SandboxLog::record($sandbox, SandboxLog::NETWORK, null, ['mode' => $network['mode'], 'egress_allow' => $network['egress_allow']]);

        return $sandbox->refresh();
    }

    /** The Secret sbx-<id>-env with the sandbox's env (none without env). */
    public function applyEnvSecret(KubernetesClient $k8s, Sandbox $sandbox): void
    {
        $secret = SandboxManifests::envSecret($sandbox);
        if ($secret !== null && ! $k8s->applyResource($secret, $sandbox->namespace)) {
            throw SandboxException::cluster(__('sandboxes.secret_failed', ['namespace' => $sandbox->namespace]));
        }
    }

    public function applyEgressPolicy(KubernetesClient $k8s, Sandbox $sandbox): void
    {
        $policy = SandboxManifests::egressPolicy($sandbox);
        if ($policy === null) {
            $k8s->deleteResource('networking.k8s.io/v1', 'NetworkPolicy', SandboxManifests::egressPolicyName($sandbox->id), $sandbox->namespace);

            return;
        }

        if (! $k8s->applyResource($policy, $sandbox->namespace)) {
            throw SandboxException::cluster(__('sandboxes.policy_failed', ['namespace' => $sandbox->namespace]));
        }
    }

    // ── Exec ────────────────────────────────────────────────────────────────────────────────

    /**
     * Run a user command (ExecScript wrapper) in a running sandbox and reset its idle timer.
     *
     * @param  string|list<string>  $cmd
     *
     * @throws SandboxException
     */
    public function exec(
        Sandbox $sandbox,
        string|array $cmd,
        array $env = [],
        ?string $cwd = null,
        ?string $stdin = null,
        ?int $timeout = null,
        ?callable $onOutput = null,
        ?callable $onTick = null,
        ?string $execId = null,
    ): ExecResult {
        $this->assertRunning($sandbox);
        $timeout = $this->timeout($timeout);
        RequestTime::allow($timeout);
        $region = $this->regionOf($sandbox);

        $argv = ExecScript::command($cmd, $env, $cwd, $timeout, $sandbox->pids_limit, $execId);

        $sandbox->touchActivity();
        $result = $this->rawExec($sandbox, $region, $argv, $stdin, $timeout, $onOutput, $onTick, (int) config('sandbox.exec.max_output', 10485760));
        $sandbox->touchActivity();

        return $result;
    }

    /**
     * Run an internal script (no wrapper) in the sandbox's container.
     *
     * @throws SandboxException cluster_error
     */
    public function rawExec(Sandbox $sandbox, KnativeRegion $region, array $argv, ?string $stdin = null, int $timeout = 60, ?callable $onOutput = null, ?callable $onTick = null, ?int $maxOutput = null): ExecResult
    {
        try {
            return $this->transport->exec(
                PodTarget::of($sandbox, $region),
                $argv,
                $stdin,
                $timeout,
                $onOutput,
                $maxOutput ?? (int) config('sandbox.files.max_download_bytes', 52428800) + 4096,
                $onTick,
            );
        } catch (ExecTransportException $e) {
            throw SandboxException::cluster($e->getMessage());
        }
    }

    /** Bounded exec timeout. */
    public function timeout(?int $timeout): int
    {
        $timeout = $timeout ?? (int) config('sandbox.exec.default_timeout', 60);

        return max(1, min($timeout, (int) config('sandbox.exec.max_timeout', 3600)));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────

    public function regionOf(Sandbox $sandbox): KnativeRegion
    {
        $region = SandboxContext::region($sandbox->knative_region_id, $sandbox->tenant_id);
        if (! $region || ! $region->kubeconfig) {
            throw new SandboxException(__('sandboxes.region_gone'), 409, 'region_unavailable');
        }

        return $region;
    }

    public function k8s(KnativeRegion $region): KubernetesClient
    {
        return new KubernetesClient((string) $region->kubeconfig);
    }

    public function assertRunning(Sandbox $sandbox): void
    {
        if ($sandbox->status !== Sandbox::STATUS_RUNNING) {
            throw SandboxException::notRunning($sandbox->status);
        }
    }

    public function assertAlive(Sandbox $sandbox): void
    {
        if (! in_array($sandbox->status, [Sandbox::STATUS_RUNNING, Sandbox::STATUS_PAUSED, Sandbox::STATUS_PENDING], true)) {
            throw SandboxException::notRunning($sandbox->status);
        }
    }

    /**
     * runtime + version → [runtime, version, image, manifest].
     *
     * @throws SandboxException runtime_unavailable
     */
    protected function runtimeImage(string $runtime, ?string $version, KnativeRegion $region): array
    {
        if ($reason = $this->runtimes->unavailableReason($runtime, $region->id)) {
            $this->invalid('runtime', $reason, 'runtime_unavailable');
        }

        /** @var RuntimeManifest $manifest */
        $manifest = $this->runtimes->require($runtime);
        $executor = ExecutorImage::resolve($runtime, $version);
        if (! $executor || ($version !== null && $executor->version !== $version)) {
            $this->invalid('version', __('sandboxes.version_unknown', ['runtime' => $runtime, 'version' => (string) $version]), 'runtime_unavailable');
        }

        try {
            $image = $this->runtimes->versionImage($manifest, $executor, $region);
        } catch (RuntimeImagePendingException $e) {
            throw new SandboxException($e->getMessage(), 409, 'runtime_image_building');
        } catch (\RuntimeException $e) {
            $this->invalid('runtime', $e->getMessage(), 'runtime_unavailable');
        }

        return [$runtime, $executor->version, $image, $manifest];
    }

    /**
     * @return list<array{path: string, content: string, mode: int}>
     */
    protected function files(array $files): array
    {
        $out = [];
        $total = 0;
        $max = (int) config('sandbox.limits.max_files_bytes', 10485760);

        foreach ($files as $i => $file) {
            $path = SandboxFiles::normalizePath((string) ($file['path'] ?? ''), "files.{$i}.path");
            $content = base64_decode((string) ($file['content_base64'] ?? ''), true);
            if ($content === false) {
                $this->invalid("files.{$i}.content_base64", __('sandboxes.base64_invalid'));
            }
            $mode = isset($file['mode']) ? octdec(ltrim((string) $file['mode'], '0') ?: '0') : 0644;
            if ($mode <= 0 || $mode > 0777) {
                $mode = 0644;
            }
            $total += strlen($content);
            if ($total > $max) {
                throw new SandboxException(__('sandboxes.files_too_large', ['max' => $max]), 413, 'file_too_large');
            }
            $out[] = ['path' => $path, 'content' => $content, 'mode' => (int) $mode];
        }

        return $out;
    }

    /** Files of a create request wait for the pod in the cache (encrypted), not in the database. */
    protected function stashFiles(Sandbox $sandbox, array $files): void
    {
        if ($files !== []) {
            Cache::put('sandbox-files:'.$sandbox->id, Crypt::encryptString(serialize($files)), now()->addMinutes(30));
        }
    }

    protected function takeFiles(Sandbox $sandbox): array
    {
        $raw = Cache::pull('sandbox-files:'.$sandbox->id);
        if (! $raw) {
            return [];
        }

        try {
            $files = unserialize(Crypt::decryptString($raw), ['allowed_classes' => false]);
        } catch (\Throwable) {
            return [];
        }

        return is_array($files) ? $files : [];
    }

    protected function forgetFiles(Sandbox $sandbox): void
    {
        Cache::forget('sandbox-files:'.$sandbox->id);
    }

    /** A UID of the configured range no live sandbox of the region uses. */
    public function allocateUid(string $regionId): int
    {
        [$min, $max] = (array) config('sandbox.uid_range', [100000, 165535]);
        for ($i = 0; $i < 8; $i++) {
            $uid = random_int((int) $min, (int) $max);
            $taken = Sandbox::where('knative_region_id', $regionId)->whereIn('status', Sandbox::LIVE_STATUSES)->where('uid', $uid)->exists();
            if (! $taken) {
                return $uid;
            }
        }

        return random_int((int) $min, (int) $max);
    }

    protected function invalid(string $field, string $message, string $reason = 'invalid'): never
    {
        throw new SandboxException($message, 422, $reason, ['errors' => [$field => [$message]]]);
    }
}
