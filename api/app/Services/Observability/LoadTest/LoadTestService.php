<?php

namespace App\Services\Observability\LoadTest;

use App\Events\PlatformEvent;
use App\Jobs\LoadTests\CheckLoadTestJob;
use App\Jobs\LoadTests\StartLoadTestJob;
use App\Models\Observability\LoadTest;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Services\KubernetesClient;
use App\Workflows\Engine\EndpointResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Load tests of a project (docs/load-test.md): what a test may target and choose, the abuse guard
 * when one starts, and its life — started in the region (StartLoadTestJob), followed until k6 is
 * done (CheckLoadTestJob), results collected, or cancelled.
 *
 * Abuse guard: only the project's own URL (its function host or one of its active custom
 * domains — the request is built from host + path, never a URL of the caller), one test at a
 * time per project, the user / duration choices of the configuration, and a daily limit per
 * organization (the rows of its own database).
 */
class LoadTestService
{
    /** Seconds between two checks of a running test */
    public const POLL_SECONDS = 5;

    /** How long a pod that cannot start (image pull, quota) is waited for */
    public const STUCK_GRACE_SECONDS = 60;

    public function __construct(private LoadTestRunner $runner, private LoadTestResults $results) {}

    public static function enabled(): bool
    {
        return (bool) config('observability.load_test.enabled', true);
    }

    /** @return list<int> */
    public static function userChoices(): array
    {
        $max = (int) config('observability.load_test.max_users', 100);

        return array_values(array_filter(array_map('intval', (array) config('observability.load_test.users', LoadTestManifests::USERS)), fn (int $u) => $u > 0 && $u <= $max));
    }

    /** @return list<int> seconds */
    public static function durationChoices(): array
    {
        $max = (int) config('observability.load_test.max_duration', 300);

        return array_values(array_filter(array_map('intval', (array) config('observability.load_test.durations', LoadTestManifests::DURATIONS)), fn (int $d) => $d > 0 && $d <= $max));
    }

    public static function dailyLimit(): int
    {
        return max(0, (int) config('observability.load_test.daily_limit', 20));
    }

    /** Tests started today in this organization (its database). */
    public static function usedToday(): int
    {
        return LoadTest::query()->where('created_at', '>=', now()->startOfDay())->count();
    }

    /**
     * Hosts a test of the project may target: the function host (https://<slug>.<region domain>)
     * and the project's custom domains routed on the region (status active = ownership verified
     * and routed). Empty for a project without an HTTP endpoint.
     *
     * @return list<string>
     */
    public function targets(Project $project): array
    {
        if (! self::httpTriggered($project)) {
            return [];
        }

        $hosts = [];
        $function = app(EndpointResolver::class)->functionUrl($project);
        if ($function !== null && ($host = parse_url($function, PHP_URL_HOST))) {
            $hosts[] = strtolower($host);
        }

        $domains = ProjectDomain::query()
            ->where('project_id', $project->id)
            ->where('status', ProjectDomain::STATUS_ACTIVE)
            ->orderBy('domain')
            ->pluck('domain');
        foreach ($domains as $domain) {
            $hosts[] = strtolower((string) $domain);
        }

        return array_values(array_unique($hosts));
    }

    public static function httpTriggered(Project $project): bool
    {
        $config = match ($project->service_type) {
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            'caas' => $project->containerConfig,
            default => null,
        };

        return $config !== null && ($config->trigger_type ?? 'http') === 'http';
    }

    /**
     * What the console's form offers (GET …/load-tests meta).
     *
     * @return array<string, mixed>
     */
    public function options(Project $project): array
    {
        return [
            'enabled' => self::enabled(),
            'users' => self::userChoices(),
            'durations' => self::durationChoices(),
            'methods' => LoadTest::METHODS,
            'targets' => $this->targets($project),
            'daily_limit' => self::dailyLimit(),
            'used_today' => self::usedToday(),
            'max_body_bytes' => (int) config('observability.load_test.max_body_bytes', 4096),
            'metered' => true,
        ];
    }

    /**
     * Start a test: every check of the abuse guard, then the row (pending) and the Job's start in
     * the background. $data: validated host / path / method / body / content_type / users /
     * duration_seconds.
     *
     * @throws LoadTestException
     */
    public function start(Project $project, array $data, ?User $user): LoadTest
    {
        if (! self::enabled()) {
            throw new LoadTestException(__('observability.load_test.disabled'), 403, 'load_tests_disabled');
        }
        if (! self::httpTriggered($project)) {
            throw new LoadTestException(__('observability.load_test.not_http'), 422, 'not_http');
        }
        if ($project->isStopped()) {
            throw new LoadTestException(__('api.project.stopped', ['reason' => (string) $project->stop_reason]), 409, 'project_stopped');
        }

        $region = $project->knativeRegion;
        if (! $region || ! $region->kubeconfig || ! $region->is_active || $region->is_migrating) {
            throw new LoadTestException(__('observability.load_test.region_unavailable', ['region' => $region->name ?? '—']), 409, 'region_unavailable');
        }

        $deployment = $project->activeDeployment()->first();
        if (! $deployment) {
            throw new LoadTestException(__('observability.load_test.not_deployed'), 422, 'not_deployed');
        }

        $targets = $this->targets($project);
        $host = strtolower(trim((string) ($data['host'] ?? '')));
        $host = $host === '' ? ($targets[0] ?? '') : $host;
        if ($host === '' || ! in_array($host, $targets, true)) {
            throw new LoadTestException(__('observability.load_test.host_not_allowed', ['host' => $host]), 422, 'host_not_allowed', ['errors' => ['host' => [__('observability.load_test.host_not_allowed', ['host' => $host])]]]);
        }

        // one test per project, the daily limit: checked and taken together
        $lock = Cache::lock('load-test:start:'.$project->id, 10);
        if (! $lock->get()) {
            throw new LoadTestException(__('observability.load_test.already_running'), 409, 'load_test_running');
        }

        try {
            $this->expireStale($project);

            $running = LoadTest::query()->where('project_id', $project->id)->whereIn('status', LoadTest::ACTIVE)->first();
            if ($running) {
                throw new LoadTestException(__('observability.load_test.already_running'), 409, 'load_test_running', ['load_test_id' => $running->id]);
            }

            $limit = self::dailyLimit();
            if (self::usedToday() >= $limit) {
                throw new LoadTestException(__('observability.load_test.daily_limit', ['limit' => $limit]), 429, 'daily_limit_reached', ['daily_limit' => $limit]);
            }

            $method = strtoupper((string) ($data['method'] ?? 'GET'));
            $body = $method === 'POST' && isset($data['body']) && $data['body'] !== '' ? (string) $data['body'] : null;

            $test = LoadTest::query()->create([
                'project_id' => $project->id,
                'deployment_id' => $deployment->getKey(),
                'region_id' => $region->id,
                'status' => LoadTest::PENDING,
                'method' => $method,
                'host' => $host,
                'path' => (string) ($data['path'] ?? '/') ?: '/',
                'body' => $body,
                'content_type' => $body !== null ? ($data['content_type'] ?? null) : null,
                'users' => (int) $data['users'],
                'duration_seconds' => (int) $data['duration_seconds'],
                'started_by' => $user?->getKey() !== null ? (string) $user->getKey() : null,
            ]);
        } finally {
            $lock->release();
        }

        $this->broadcast($test);
        StartLoadTestJob::dispatch($test, $test->started_by);

        return $test;
    }

    /**
     * StartLoadTestJob: the Job in the region; the test is running from then on and followed by
     * CheckLoadTestJob. A refusal of the region fails the test with its reason.
     */
    public function launch(LoadTest $test, ?string $initiatorId = null): bool
    {
        $test->loadMissing('project.knativeRegion');
        $region = $test->project?->knativeRegion;
        if ($test->status !== LoadTest::PENDING) {
            return false;
        }
        if (! $region) {
            $this->fail($test, __('observability.load_test.region_unavailable', ['region' => '—']));

            return false;
        }

        try {
            $this->runner->launch($test, $region);
        } catch (LoadTestException $e) {
            $this->fail($test, $e->getMessage());

            return false;
        }

        // cancelled while the Job was being created: the Job goes again
        $started = now();
        $moved = LoadTest::query()->whereKey($test->id)->where('status', LoadTest::PENDING)
            ->update(['status' => LoadTest::RUNNING, 'started_at' => $started, 'updated_at' => $started]);
        if ($moved === 0) {
            $this->runner->remove($this->runner->client($region), $test);

            return false;
        }
        $test->forceFill(['status' => LoadTest::RUNNING, 'started_at' => $started])->syncOriginal();
        $this->broadcast($test);
        CheckLoadTestJob::dispatch($test, $initiatorId)->delay(now()->addSeconds(min(self::POLL_SECONDS * 2, $test->duration_seconds)));

        return true;
    }

    /**
     * One check of a running test (CheckLoadTestJob). True when the test is over — completed with
     * its results, or failed with the reason.
     */
    public function poll(LoadTest $test): bool
    {
        if ($test->status !== LoadTest::RUNNING) {
            return true;
        }
        $test->loadMissing('project.knativeRegion');
        $region = $test->project?->knativeRegion;
        if (! $region || ! $region->kubeconfig) {
            $this->fail($test, __('observability.load_test.region_unavailable', ['region' => $region->name ?? '—']));

            return true;
        }

        $k8s = $this->runner->client($region);
        $state = $this->runner->state($k8s, $test);

        switch ($state['state']) {
            case 'missing':
                $this->fail($test, __('observability.load_test.job_missing'));

                return true;

            case 'stuck':
                // a pull that backs off, a pod the namespace's quota holds back: given a minute
                if (now()->lessThan(($test->started_at ?? now())->copy()->addSeconds(self::STUCK_GRACE_SECONDS))) {
                    return false;
                }
                $this->runner->remove($k8s, $test);
                $this->fail($test, __('observability.load_test.job_stuck', ['reason' => (string) $state['message']]));

                return true;

            case 'succeeded':
            case 'failed':
                $this->collect($test, $k8s, $state);

                return true;
        }

        if (now()->greaterThan($test->deadline()->addSeconds(30))) {
            $this->runner->remove($k8s, $test);
            $this->fail($test, __('observability.load_test.timed_out', ['seconds' => $test->duration_seconds]));

            return true;
        }

        return false;
    }

    /**
     * The k6 results from the pod's log and RunR's metrics of the window. k6 may exit with an
     * error after printing them (the results stand); without them the test failed with the end of
     * k6's log or the Job's reason.
     *
     * @param  array{state: string, pod: string|null, message: string|null, started_at: mixed, finished_at: mixed}  $state
     */
    private function collect(LoadTest $test, KubernetesClient $k8s, array $state): void
    {
        $log = $state['pod'] ? $this->runner->logs($k8s, $test, $state['pod']) : '';
        $summary = LoadTestResults::parseSummary($log);
        $this->runner->remove($k8s, $test);

        if ($summary === null) {
            $reason = LoadTestRunner::logTail($log) ?: ($state['message'] ?? __('observability.load_test.no_results'));
            $this->fail($test, __('observability.load_test.no_results_reason', ['reason' => $reason]));

            return;
        }

        $from = $state['started_at'] ?? $test->started_at ?? now()->subSeconds($test->duration_seconds);
        $to = $state['finished_at'] ?? now();
        $scaling = [];
        try {
            $scaling = $this->results->scaling($test->project, $from, $to);
        } catch (\Throwable $e) {
            Log::warning('Load test: scaling metrics not read', ['load_test' => $test->id, 'error' => $e->getMessage()]);
        }

        $test->forceFill([
            'status' => LoadTest::COMPLETED,
            'results' => $summary + $scaling + ['window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()]],
            'error' => null,
            'finished_at' => now(),
        ])->save();
        $this->broadcast($test);
    }

    /**
     * Stop a pending or running test: its Job is removed, the requests stop.
     */
    public function cancel(LoadTest $test): LoadTest
    {
        if (! $test->isActive()) {
            return $test;
        }

        $region = $test->project?->knativeRegion;
        if ($region && $region->kubeconfig && $test->job_name) {
            try {
                $this->runner->remove($this->runner->client($region), $test);
            } catch (\Throwable $e) {
                Log::info('Load test: cancel could not reach the region', ['load_test' => $test->id, 'error' => $e->getMessage()]);
            }
        }

        $test->forceFill(['status' => LoadTest::CANCELLED, 'finished_at' => now()])->save();
        $this->broadcast($test);

        return $test;
    }

    public function fail(LoadTest $test, string $reason): void
    {
        $test->forceFill(['status' => LoadTest::FAILED, 'error' => mb_substr($reason, 0, 2000), 'finished_at' => now()])->save();
        $this->broadcast($test);
    }

    /**
     * The tests of the current organization that run in the region, ended because the region
     * stopped serving them ($reason): its Knative Serving was uninstalled, or the organization's
     * share of it ended. Their Jobs are removed by the caller (LoadTestRunner). Returns how many.
     */
    public function endForRegion(string $regionId, string $reason): int
    {
        $ended = 0;
        foreach (LoadTest::query()->where('region_id', $regionId)->whereIn('status', LoadTest::ACTIVE)->get() as $test) {
            $this->fail($test, $reason);
            $ended++;
        }

        return $ended;
    }

    /**
     * Tests of the project that are past their deadline and still hold its slot (their checks were
     * lost, e.g. a queue restart): failed, so the project can be tested again.
     */
    public function expireStale(Project $project): void
    {
        $active = LoadTest::query()->where('project_id', $project->id)->whereIn('status', LoadTest::ACTIVE)->get();
        foreach ($active as $test) {
            if (now()->greaterThan($test->deadline()->addSeconds(60))) {
                $this->fail($test, __('observability.load_test.timed_out', ['seconds' => $test->duration_seconds]));
            }
        }
    }

    /** load_test.updated on the organization's platform channel (no request body). */
    public function broadcast(LoadTest $test): void
    {
        try {
            event(new PlatformEvent('load_test.updated', ['project_id' => $test->project_id, 'load_test' => $test->toApi(false)]));
        } catch (\Throwable $e) {
            Log::warning('Load test update not broadcast', ['load_test' => $test->id, 'error' => $e->getMessage()]);
        }
    }
}
