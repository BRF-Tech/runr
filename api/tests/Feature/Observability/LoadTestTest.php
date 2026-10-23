<?php

namespace Tests\Feature\Observability;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Events\NotificationCreated;
use App\Events\PlatformEvent;
use App\Jobs\LoadTests\CheckLoadTestJob;
use App\Jobs\LoadTests\StartLoadTestJob;
use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\Observability\LoadTest;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Models\Version;
use App\Services\KubernetesClient;
use App\Services\Observability\LoadTest\LoadTestManifests;
use App\Services\Observability\LoadTest\LoadTestRunner;
use App\Services\Observability\LoadTest\LoadTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * Load tests (docs/load-test.md): the abuse guard of POST /api/projects/{p}/load-tests (only the
 * project's own hosts, the user / duration choices, one test per project, the organization's
 * daily limit, projects that cannot be tested), permissions, the k6 Job in the region's
 * runr-loadtest namespace (policies, quota, script, security context), the check that reads k6's
 * results from the pod log with the cold starts and pods of the window, failures, cancel and the
 * list with the form's options.
 */
class LoadTestTest extends TestCase
{
    use ActsWithRoles, FakesObservabilityCluster, RefreshDatabase;

    private const NS = '/api/v1/namespaces/runr-loadtest';

    private const JOBS = '/apis/batch/v1/namespaces/runr-loadtest/jobs';

    private const PROMETHEUS = '/api/v1/namespaces/observability/services/http:prometheus-operated:9090/proxy/api/v1/query';

    private User $owner;

    private KnativeRegion $region;

    private Project $project;

    private Deployment $deployment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();
        Queue::fake();
        Event::fake([PlatformEvent::class, NotificationCreated::class, DeploymentCreated::class, DeploymentStatusChanged::class]);
        // not running in a pod: Prometheus is read through the region's API proxy
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);

        $this->owner = $this->userWithRole('owner');
        $this->region = $this->obsRegion(['knative_settings' => ['knative_domain' => 'eu.runr.test']]);
        $this->project = $this->obsProject($this->region);
        $this->deployment = $this->deployed($this->project);

        $this->k8sRoutes['GET /api/v1/namespaces/knative-serving/services/traefik'] = fn () => [200, ['spec' => ['clusterIP' => '10.96.0.42']]];
    }

    private function deployed(Project $project): Deployment
    {
        $version = Version::create([
            'project_id' => $project->id, 'number' => 1, 'commit_id' => 'c1', 'commit_message' => 'v1', 'created_by' => 'dev', 'build_status' => 'ready',
        ]);

        return Deployment::query()->create([
            'project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active', 'k8s_job_name' => 'run-'.$project->slug.'-v1', 'deployed_by' => 'dev',
        ]);
    }

    private function start(array $input = [], ?User $user = null): TestResponse
    {
        return $this->signedIn($user ?? $this->owner)->postJson("/api/projects/{$this->project->id}/load-tests", $input + ['users' => 10, 'duration_seconds' => 30]);
    }

    private function running(array $attributes = []): LoadTest
    {
        // forceFill: created_at of a test of the past is kept
        return tap((new LoadTest)->forceFill(array_merge([
            'project_id' => $this->project->id,
            'deployment_id' => $this->deployment->id,
            'region_id' => $this->region->id,
            'status' => LoadTest::RUNNING,
            'host' => 'orders.eu.runr.test',
            'path' => '/',
            'users' => 10,
            'duration_seconds' => 30,
            'job_name' => null,
            'namespace' => 'runr-loadtest',
            'started_by' => (string) $this->owner->id,
            'started_at' => now()->subSeconds(40),
        ], $attributes)))->save();
    }

    // ── starting ──────────────────────────────────────────────────────────────

    public function test_a_test_starts_on_the_function_host_and_is_started_in_the_background(): void
    {
        $id = $this->start(['path' => '/api/orders?page=2'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.host', 'orders.eu.runr.test')
            ->assertJsonPath('data.url', 'https://orders.eu.runr.test/api/orders?page=2')
            ->assertJsonPath('data.users', 10)
            ->assertJsonPath('data.duration_seconds', 30)
            ->assertJsonPath('data.started_by.id', (string) $this->owner->id)
            ->json('data.id');

        $test = LoadTest::query()->findOrFail($id);
        $this->assertSame([$this->deployment->id, $this->region->id, 'GET'], [$test->deployment_id, $test->region_id, $test->method]);
        Queue::assertPushed(StartLoadTestJob::class, fn (StartLoadTestJob $job) => $job->test->is($test) && $job->initiatorId === (string) $this->owner->id);
        Event::assertDispatched(PlatformEvent::class, fn (PlatformEvent $e) => $e->topic === 'load_test.updated'
            && $e->payload['load_test']['id'] === $id && ! array_key_exists('body', $e->payload['load_test']));
    }

    public function test_only_the_projects_own_hosts_are_targets(): void
    {
        ProjectDomain::query()->create(['project_id' => $this->project->id, 'domain' => 'shop.example.com', 'status' => ProjectDomain::STATUS_ACTIVE, 'verification_token' => 't1', 'tls_mode' => 'none']);
        ProjectDomain::query()->create(['project_id' => $this->project->id, 'domain' => 'pending.example.com', 'status' => ProjectDomain::STATUS_PENDING, 'verification_token' => 't2', 'tls_mode' => 'none']);
        $other = $this->obsProject($this->region, 'billing');
        ProjectDomain::query()->create(['project_id' => $other->id, 'domain' => 'billing.example.com', 'status' => ProjectDomain::STATUS_ACTIVE, 'verification_token' => 't3', 'tls_mode' => 'none']);

        foreach (['example.com', 'billing.eu.runr.test', 'billing.example.com', 'pending.example.com', '10.0.0.1'] as $host) {
            $this->start(['host' => $host])->assertUnprocessable()
                ->assertJsonPath('code', 'host_not_allowed')
                ->assertJsonValidationErrors(['host']);
        }

        $this->start(['host' => 'SHOP.example.com'])->assertStatus(202)->assertJsonPath('data.host', 'shop.example.com');
        $this->assertSame(1, LoadTest::query()->count());
    }

    public function test_choices_path_and_body_are_validated(): void
    {
        $this->start(['users' => 20, 'duration_seconds' => 120])->assertUnprocessable()->assertJsonValidationErrors(['users', 'duration_seconds']);
        $this->start(['path' => 'https://evil.example.com/'])->assertUnprocessable()->assertJsonValidationErrors(['path']);
        $this->start(['path' => '/a b'])->assertUnprocessable()->assertJsonValidationErrors(['path']);
        $this->start(['path' => "/a\nb"])->assertUnprocessable()->assertJsonValidationErrors(['path']);
        $this->start(['method' => 'DELETE'])->assertUnprocessable()->assertJsonValidationErrors(['method']);
        $this->start(['body' => '{"a":1}'])->assertUnprocessable()->assertJsonValidationErrors(['body']);
        $this->start(['method' => 'POST', 'body' => str_repeat('x', 4097)])->assertUnprocessable()->assertJsonValidationErrors(['body']);
        $this->start(['method' => 'POST', 'body' => '{}', 'content_type' => "text/plain\r\nX-Evil: 1"])->assertUnprocessable()->assertJsonValidationErrors(['content_type']);

        // a lower cap of the installation drops the larger choices
        config(['observability.load_test.max_users' => 50, 'observability.load_test.max_duration' => 60]);
        $this->start(['users' => 100])->assertUnprocessable()->assertJsonValidationErrors(['users']);
        $this->start(['duration_seconds' => 300])->assertUnprocessable()->assertJsonValidationErrors(['duration_seconds']);
        $this->assertSame(0, LoadTest::query()->count());

        $this->start(['method' => 'POST', 'body' => '{"item":42}', 'users' => 50, 'duration_seconds' => 60])->assertStatus(202)
            ->assertJsonPath('data.method', 'POST')
            ->assertJsonPath('data.has_body', true)
            ->assertJsonPath('data.body', '{"item":42}');
    }

    public function test_one_test_per_project_and_the_daily_limit_of_the_organization(): void
    {
        $first = $this->start()->assertStatus(202)->json('data.id');
        $this->start()->assertStatus(409)->assertJsonPath('code', 'load_test_running')->assertJsonPath('load_test_id', $first);

        // another project of the organization is not held up by it
        $other = $this->obsProject($this->region, 'billing');
        $this->deployed($other);
        $this->signedIn($this->owner)->postJson("/api/projects/{$other->id}/load-tests", ['users' => 10, 'duration_seconds' => 30])->assertStatus(202);

        LoadTest::query()->whereKey($first)->update(['status' => LoadTest::COMPLETED]);
        config(['observability.load_test.daily_limit' => 2]);
        $this->start()->assertStatus(429)->assertJsonPath('code', 'daily_limit_reached')->assertJsonPath('daily_limit', 2);

        // yesterday's tests do not count
        LoadTest::query()->update(['created_at' => now()->subDay()]);
        $this->start()->assertStatus(202);
    }

    public function test_a_test_past_its_deadline_no_longer_holds_the_project(): void
    {
        $stale = $this->running(['started_at' => now()->subHour(), 'created_at' => now()->subHour()]);

        $this->start()->assertStatus(202);
        $this->assertSame(LoadTest::FAILED, $stale->fresh()->status);
        $this->assertNotNull($stale->fresh()->error);
    }

    public function test_projects_that_cannot_be_tested_are_refused(): void
    {
        $this->deployment->update(['status' => 'shut_down']);
        $this->start()->assertUnprocessable()->assertJsonPath('code', 'not_deployed');
        $this->deployment->update(['status' => 'active']);

        $queue = $this->obsProject($this->region, 'worker', 'queue');
        $this->deployed($queue);
        $this->signedIn($this->owner)->postJson("/api/projects/{$queue->id}/load-tests", ['users' => 10, 'duration_seconds' => 30])
            ->assertUnprocessable()->assertJsonPath('code', 'not_http');

        $this->project->forceFill(['stopped_at' => now(), 'stop_reason' => 'unpaid'])->saveQuietly();
        $this->start()->assertStatus(409)->assertJsonPath('code', 'project_stopped');
        $this->project->forceFill(['stopped_at' => null])->saveQuietly();

        $this->region->forceFill(['region_status' => 'maintenance', 'is_active' => false])->saveQuietly();
        $this->start()->assertStatus(409)->assertJsonPath('code', 'region_unavailable');
        $this->region->forceFill(['region_status' => 'active', 'is_active' => true, 'migration_status' => 'in_progress'])->saveQuietly();
        $this->start()->assertStatus(409)->assertJsonPath('code', 'region_unavailable');
        $this->region->forceFill(['migration_status' => null])->saveQuietly();

        config(['observability.load_test.enabled' => false]);
        $this->start()->assertForbidden()->assertJsonPath('code', 'load_tests_disabled');

        $this->assertSame(0, LoadTest::query()->count());
        Queue::assertNotPushed(StartLoadTestJob::class);
    }

    public function test_reading_needs_metrics_view_and_running_needs_deploy(): void
    {
        $viewer = $this->userWithRole('viewer');
        $developer = $this->userWithRole('developer');
        $test = $this->running(['status' => LoadTest::COMPLETED]);

        $this->start([], $viewer)->assertForbidden()->assertJsonPath('permissions', ['projects.deploy']);
        $this->signedIn($viewer)->postJson("/api/projects/{$this->project->id}/load-tests/{$test->id}/cancel")->assertForbidden();
        $this->signedIn($viewer)->getJson("/api/projects/{$this->project->id}/load-tests")->assertOk()->assertJsonPath('data.0.id', $test->id);
        $this->signedIn($viewer)->getJson("/api/projects/{$this->project->id}/load-tests/{$test->id}")->assertOk()->assertJsonPath('data.status', 'completed');

        $this->start([], $developer)->assertStatus(202);

        // another project's test is not reachable through this project
        $other = $this->obsProject($this->region, 'billing');
        $this->signedIn($this->owner)->getJson("/api/projects/{$other->id}/load-tests/{$test->id}")->assertNotFound();
    }

    public function test_the_list_carries_the_choices_targets_and_the_daily_count(): void
    {
        ProjectDomain::query()->create(['project_id' => $this->project->id, 'domain' => 'shop.example.com', 'status' => ProjectDomain::STATUS_ACTIVE, 'verification_token' => 't', 'tls_mode' => 'none']);
        foreach (range(1, 7) as $i) {
            $this->running(['status' => LoadTest::COMPLETED, 'created_at' => now()->subSeconds(10 - $i)]);
        }
        config(['observability.load_test.max_users' => 50]);

        $response = $this->signedIn($this->owner)->getJson("/api/projects/{$this->project->id}/load-tests?limit=5")->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.users', [10, 50])
            ->assertJsonPath('meta.durations', [30, 60, 300])
            ->assertJsonPath('meta.methods', ['GET', 'POST'])
            ->assertJsonPath('meta.targets', ['orders.eu.runr.test', 'shop.example.com'])
            ->assertJsonPath('meta.daily_limit', 20)
            ->assertJsonPath('meta.used_today', 7)
            ->assertJsonPath('meta.metered', true);

        $created = array_column($response->json('data'), 'created_at');
        $sorted = $created;
        rsort($sorted);
        $this->assertSame($sorted, $created, 'newest first');
    }

    // ── the Job in the region ─────────────────────────────────────────────────

    public function test_the_start_applies_the_namespace_policies_script_and_job(): void
    {
        $this->k8sRoutes['GET '.self::JOBS.'/'] = fn () => [200, ['metadata' => ['uid' => 'job-uid-1']]];
        $id = $this->start(['method' => 'POST', 'body' => '{"q":"</script>\'"}', 'path' => '/orders'])->assertStatus(202)->json('data.id');
        $test = LoadTest::query()->findOrFail($id);
        $name = 'lt-'.strtolower($id);

        (new StartLoadTestJob($test, (string) $this->owner->id))->handle(app(LoadTestService::class));

        $test->refresh();
        $this->assertSame(LoadTest::RUNNING, $test->status);
        $this->assertSame([$name, 'runr-loadtest'], [$test->job_name, $test->namespace]);
        $this->assertNotNull($test->started_at);
        Queue::assertPushed(CheckLoadTestJob::class, fn (CheckLoadTestJob $job) => $job->test->is($test));

        // the namespace: restricted pods, no traffic but to the region's Traefik, a pod quota
        $ns = $this->k8sCallsTo('PATCH', '/api/v1/namespaces/runr-loadtest')[0]['body'];
        $this->assertSame('restricted', $ns['metadata']['labels']['pod-security.kubernetes.io/enforce']);
        $deny = $this->k8sCallsTo('PATCH', '/apis/networking.k8s.io/v1/namespaces/runr-loadtest/networkpolicies/runr-loadtest-default-deny')[0]['body'];
        $this->assertSame(['Ingress', 'Egress'], $deny['spec']['policyTypes']);
        $egress = $this->k8sCallsTo('PATCH', '/apis/networking.k8s.io/v1/namespaces/runr-loadtest/networkpolicies/runr-loadtest-egress')[0]['body'];
        $this->assertSame([[
            'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => 'knative-serving']],
            'podSelector' => ['matchLabels' => ['app.kubernetes.io/name' => 'traefik']],
        ]], $egress['spec']['egress'][0]['to']);
        $this->assertArrayNotHasKey('ports', $egress['spec']['egress'][0]);
        $quota = $this->k8sCallsTo('PATCH', self::NS.'/resourcequotas/runr-loadtest-quota')[0]['body'];
        $this->assertSame('5', $quota['spec']['hard']['count/pods']);

        // the generated script: the host pinned to the ingress, the request as JSON literals
        $cm = $this->k8sCallsTo('PATCH', self::NS.'/configmaps/'.$name)[0]['body'];
        $script = $cm['data']['script.js'];
        $this->assertStringContainsString('"hosts":{"orders.eu.runr.test":"10.96.0.42"}', $script);
        $this->assertStringContainsString('const URL = "https://orders.eu.runr.test/orders";', $script);
        $this->assertStringContainsString('const METHOD = "POST";', $script);
        $this->assertStringContainsString('const BODY = "{\"q\":\"</script>\'\"}";', $script);
        $this->assertStringContainsString('"vus":10', $script);
        $this->assertStringContainsString('"duration":"30s"', $script);
        $this->assertStringContainsString('"Content-Type":"application/json"', $script);
        $this->assertStringContainsString(LoadTestManifests::SUMMARY_MARKER, $script);

        // the Job: one pod, no token, no secrets, non-root, limits, deadline and TTL
        $job = $this->k8sCallsTo('POST', self::JOBS)[0]['body'];
        $this->assertSame(0, $job['spec']['backoffLimit']);
        $this->assertSame(30 + 180, $job['spec']['activeDeadlineSeconds']);
        $this->assertSame(600, $job['spec']['ttlSecondsAfterFinished']);
        $pod = $job['spec']['template']['spec'];
        $this->assertFalse($pod['automountServiceAccountToken']);
        $this->assertSame('Never', $pod['restartPolicy']);
        $this->assertSame('load-test', $job['spec']['template']['metadata']['labels']['app.kubernetes.io/component']);
        $container = $pod['containers'][0];
        $this->assertSame(LoadTestManifests::IMAGE, $container['image']);
        $this->assertSame(['run', '--quiet', '--no-color', '--no-usage-report', '/scripts/script.js'], $container['args']);
        $this->assertSame(['drop' => ['ALL']], $container['securityContext']['capabilities']);
        $this->assertTrue($container['securityContext']['readOnlyRootFilesystem']);
        $this->assertSame('1', $container['resources']['limits']['cpu']);
        $this->assertSame('512Mi', $container['resources']['limits']['memory']);
        $this->assertStringNotContainsString('secret', strtolower(json_encode($pod['volumes']).json_encode($container['env'] ?? [])));

        // the ConfigMap is owned by the Job: its TTL removes both
        $owner = $this->k8sCallsTo('PATCH', self::NS.'/configmaps/'.$name)[1]['body'];
        $this->assertSame([['apiVersion' => 'batch/v1', 'kind' => 'Job', 'name' => $name, 'uid' => 'job-uid-1']], $owner['metadata']['ownerReferences']);
    }

    public function test_a_region_without_its_ingress_fails_the_test(): void
    {
        unset($this->k8sRoutes['GET /api/v1/namespaces/knative-serving/services/traefik']);
        $id = $this->start()->assertStatus(202)->json('data.id');
        $test = LoadTest::query()->findOrFail($id);

        (new StartLoadTestJob($test, (string) $this->owner->id))->handle(app(LoadTestService::class));

        $test->refresh();
        $this->assertSame(LoadTest::FAILED, $test->status);
        $this->assertStringContainsString('traefik', $test->error);
        $this->assertSame([], $this->k8sCallsTo('POST', self::JOBS), 'no Job without an address to send to');
        Queue::assertNotPushed(CheckLoadTestJob::class);
        $this->assertSame('load_test.failed', Notification::query()->where('user_id', $this->owner->id)->sole()->type);
    }

    public function test_a_test_cancelled_while_its_job_is_created_does_not_run(): void
    {
        $this->k8sRoutes['GET '.self::JOBS.'/'] = function () {
            // the cancel arrives while the start waits for the Job's uid
            LoadTest::query()->update(['status' => LoadTest::CANCELLED]);

            return [200, ['metadata' => ['uid' => 'u']]];
        };
        $id = $this->start()->assertStatus(202)->json('data.id');

        (new StartLoadTestJob(LoadTest::query()->findOrFail($id)))->handle(app(LoadTestService::class));

        $this->assertSame(LoadTest::CANCELLED, LoadTest::query()->findOrFail($id)->status);
        $this->assertCount(1, $this->k8sCallsTo('DELETE', self::JOBS.'/lt-'.strtolower($id)));
        Queue::assertNotPushed(CheckLoadTestJob::class);
    }

    // ── results ───────────────────────────────────────────────────────────────

    private function finishedJob(LoadTest $test, string $log, bool $succeeded = true, ?string $started = null, ?string $finished = null): void
    {
        $name = 'lt-'.strtolower($test->id);
        $test->forceFill(['job_name' => $name])->save();
        $this->k8sRoutes['GET '.self::JOBS.'/'.$name] = fn () => [200, ['metadata' => ['name' => $name], 'status' => $succeeded
            ? ['succeeded' => 1]
            : ['failed' => 1, 'conditions' => [['type' => 'Failed', 'status' => 'True', 'reason' => 'BackoffLimitExceeded', 'message' => 'Job has reached the specified backoff limit']]]]];
        $this->k8sRoutes['GET '.self::NS.'/pods'] = fn () => [200, ['items' => [[
            'metadata' => ['name' => $name.'-abcde'],
            'status' => ['containerStatuses' => [[
                'name' => 'k6',
                'state' => ['terminated' => ['exitCode' => $succeeded ? 0 : 107, 'startedAt' => $started ?? now()->subSeconds(35)->toIso8601String(), 'finishedAt' => $finished ?? now()->subSeconds(2)->toIso8601String()]],
            ]]],
        ]]]];
        $this->k8sRoutes['GET '.self::NS.'/pods/'.$name.'-abcde/log'] = fn () => Http::response($log, 200);
    }

    private static function summaryLine(array $overrides = []): string
    {
        return LoadTestManifests::SUMMARY_MARKER.json_encode(array_replace([
            'requests' => 12000, 'rps' => 398.734, 'error_rate' => 0.0025, 'failed_requests' => 30,
            'latency_ms' => ['avg' => 21.4, 'min' => 3.1, 'med' => 18.2, 'p50' => 18.2, 'p95' => 48.123, 'p99' => 97.5, 'max' => 412.9],
            'data_received' => 2400000, 'data_sent' => 1100000, 'vus_max' => 10, 'test_run_duration_ms' => 30012,
        ], $overrides));
    }

    public function test_the_check_reads_the_results_and_the_scaling_of_the_window(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $test = $this->running(['started_at' => now()->subSeconds(40)]);
        $this->finishedJob($test, "level=warning msg=\"Request Failed\"\n".self::summaryLine()."\n", true, '2026-10-21T11:59:25Z', '2026-10-21T11:59:58Z');

        // a cold start in the window, one before it; two pods with sessions in the window
        $session = fn (string $at, bool $cold, string $pod) => DeploymentSession::query()->create([
            'project_id' => $this->project->id, 'deployment_id' => $this->deployment->id, 'region_id' => $this->region->id,
            'entry_point' => 'http', 'started_at' => $at, 'cold_start' => $cold, 'metadata' => ['pod' => $pod],
        ]);
        $session('2026-10-21 11:59:26', true, 'orders-00001-deployment-a');
        $session('2026-10-21 11:50:00', true, 'orders-00001-deployment-old');

        $promql = null;
        $this->k8sRoutes['GET '.self::PROMETHEUS] = function (Request $request) use (&$promql) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $promql = $query['query'];

            return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [1, '3']]]]]);
        };

        (new CheckLoadTestJob($test, (string) $this->owner->id))->handle(app(LoadTestService::class));

        $test->refresh();
        $this->assertSame(LoadTest::COMPLETED, $test->status);
        $r = $test->results;
        $this->assertSame([12000, 30, 398.73, 0.0025], [$r['requests'], $r['failed_requests'], $r['rps'], $r['error_rate']]);
        $this->assertSame(['avg' => 21.4, 'min' => 3.1, 'p50' => 18.2, 'p95' => 48.12, 'p99' => 97.5, 'max' => 412.9], $r['latency_ms']);
        $this->assertSame([3, 'prometheus', 1], [$r['pods_peak'], $r['pods_source'], $r['cold_starts']]);
        $this->assertSame('2026-10-21T11:59:25+00:00', $r['window']['from']);
        $this->assertStringContainsString('namespace="fn",pod=~"orders-[0-9]+-deployment-.*",container="handler"', (string) $promql);
        $this->assertStringStartsWith('max_over_time(count(container_memory_working_set_bytes{', (string) $promql);

        // the Job and its ConfigMap go; the starter is told
        $this->assertCount(1, $this->k8sCallsTo('DELETE', self::JOBS.'/'.$test->job_name));
        $this->assertCount(1, $this->k8sCallsTo('DELETE', self::NS.'/configmaps/'.$test->job_name));
        $notification = Notification::query()->where('user_id', $this->owner->id)->sole();
        $this->assertSame('load_test.completed', $notification->type);
        $this->assertStringContainsString('48.12', (string) $notification->body);
        Queue::assertNotPushed(CheckLoadTestJob::class);
        Carbon::setTestNow();
    }

    public function test_without_prometheus_the_pods_come_from_the_sessions(): void
    {
        $test = $this->running();
        $this->finishedJob($test, self::summaryLine());
        foreach (['a', 'b', 'b'] as $pod) {
            DeploymentSession::query()->create([
                'project_id' => $this->project->id, 'deployment_id' => $this->deployment->id, 'region_id' => $this->region->id,
                'entry_point' => 'http', 'started_at' => now()->subSeconds(20), 'cold_start' => false, 'metadata' => ['pod' => "orders-00001-deployment-{$pod}"],
            ]);
        }
        $this->k8sRoutes['GET '.self::PROMETHEUS] = fn () => Http::response('no endpoints available for service', 503);

        (new CheckLoadTestJob($test))->handle(app(LoadTestService::class));

        $r = $test->fresh()->results;
        $this->assertSame([2, 'sessions', 0], [$r['pods_peak'], $r['pods_source'], $r['cold_starts']]);
    }

    public function test_a_run_without_results_fails_with_the_end_of_k6s_log(): void
    {
        $test = $this->running();
        $this->finishedJob($test, "\e[31mERRO[0000] SyntaxError: something broke\e[0m\nlevel=error msg=\"test aborted\"\n", false);

        (new CheckLoadTestJob($test, (string) $this->owner->id))->handle(app(LoadTestService::class));

        $test->refresh();
        $this->assertSame(LoadTest::FAILED, $test->status);
        $this->assertStringContainsString('SyntaxError: something broke', $test->error);
        $this->assertStringNotContainsString("\e[", $test->error);
        $this->assertNull($test->results);
        $this->assertSame('load_test.failed', Notification::query()->where('user_id', $this->owner->id)->sole()->type);
    }

    public function test_results_printed_before_k6_failed_stand(): void
    {
        $test = $this->running();
        $this->finishedJob($test, self::summaryLine(['error_rate' => 1, 'failed_requests' => 12000])."\nlevel=error msg=\"some thresholds have failed\"\n", false);

        (new CheckLoadTestJob($test))->handle(app(LoadTestService::class));

        $this->assertSame(LoadTest::COMPLETED, $test->fresh()->status);
        $this->assertEquals(1.0, $test->fresh()->results['error_rate']);
    }

    public function test_a_running_job_is_checked_again_and_a_late_one_fails(): void
    {
        $test = $this->running(['job_name' => 'lt-x']);
        $this->k8sRoutes['GET '.self::JOBS.'/lt-x'] = fn () => [200, ['metadata' => ['name' => 'lt-x'], 'status' => ['active' => 1]]];
        $this->k8sRoutes['GET '.self::NS.'/pods'] = fn () => [200, ['items' => [['metadata' => ['name' => 'lt-x-1'], 'status' => ['containerStatuses' => [['name' => 'k6', 'state' => ['running' => ['startedAt' => now()->toIso8601String()]]]]]]]]];

        (new CheckLoadTestJob($test))->handle(app(LoadTestService::class));
        $this->assertSame(LoadTest::RUNNING, $test->fresh()->status);
        Queue::assertPushed(CheckLoadTestJob::class, 1);

        $test->forceFill(['started_at' => now()->subMinutes(10)])->save();
        (new CheckLoadTestJob($test))->handle(app(LoadTestService::class));
        $this->assertSame(LoadTest::FAILED, $test->fresh()->status);
        $this->assertCount(1, $this->k8sCallsTo('DELETE', self::JOBS.'/lt-x'));
    }

    public function test_a_pod_that_cannot_pull_its_image_fails_after_the_grace(): void
    {
        $test = $this->running(['job_name' => 'lt-y', 'started_at' => now()->subSeconds(10)]);
        $this->k8sRoutes['GET '.self::JOBS.'/lt-y'] = fn () => [200, ['metadata' => ['name' => 'lt-y'], 'status' => ['active' => 1]]];
        $this->k8sRoutes['GET '.self::NS.'/pods'] = fn () => [200, ['items' => [['metadata' => ['name' => 'lt-y-1'], 'status' => ['containerStatuses' => [[
            'name' => 'k6', 'state' => ['waiting' => ['reason' => 'ImagePullBackOff', 'message' => 'Back-off pulling image "ghcr.io/brf-tech/runr/reqs/k6:1.8.2"']],
        ]]]]]]];

        (new CheckLoadTestJob($test))->handle(app(LoadTestService::class));
        $this->assertSame(LoadTest::RUNNING, $test->fresh()->status, 'a pull that backs off gets a minute');

        $test->forceFill(['started_at' => now()->subSeconds(LoadTestService::STUCK_GRACE_SECONDS + 5)])->save();
        (new CheckLoadTestJob($test))->handle(app(LoadTestService::class));
        $this->assertSame(LoadTest::FAILED, $test->fresh()->status);
        $this->assertStringContainsString('ImagePullBackOff', $test->fresh()->error);
    }

    public function test_cancel_removes_the_job(): void
    {
        $test = $this->running(['job_name' => 'lt-z']);

        $this->signedIn($this->owner)->postJson("/api/projects/{$this->project->id}/load-tests/{$test->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertCount(1, $this->k8sCallsTo('DELETE', self::JOBS.'/lt-z'));
        $this->assertSame('Background', $this->k8sCallsTo('DELETE', self::JOBS.'/lt-z')[0]['body']['propagationPolicy']);
        $this->assertNotNull($test->fresh()->finished_at);

        // a finished test stays as it is
        $this->signedIn($this->owner)->postJson("/api/projects/{$this->project->id}/load-tests/{$test->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertCount(1, $this->k8sCallsTo('DELETE', self::JOBS.'/lt-z'));
    }

    // ── the region goes ───────────────────────────────────────────────────────

    public function test_the_namespace_goes_with_the_regions_serving_and_its_tests_end(): void
    {
        $running = $this->running(['job_name' => 'lt-a']);
        $done = $this->running(['status' => LoadTest::COMPLETED, 'finished_at' => now()]);
        $this->k8sRoutes['GET '.self::NS] = fn () => [200, ['metadata' => ['name' => 'runr-loadtest', 'labels' => [
            LoadTestManifests::LABEL_MANAGED => 'runr', LoadTestManifests::LABEL_COMPONENT => LoadTestManifests::COMPONENT,
        ]]]];

        $this->assertSame(1, app(LoadTestService::class)->endForRegion($this->region->id, __('observability.load_test.region_uninstalled')));
        $this->assertTrue(app(LoadTestRunner::class)->removeNamespace(new KubernetesClient($this->region->kubeconfig)));

        $this->assertCount(1, $this->k8sCallsTo('DELETE', self::NS));
        $this->assertSame(LoadTest::FAILED, $running->fresh()->status);
        $this->assertSame(__('observability.load_test.region_uninstalled'), $running->fresh()->error);
        $this->assertSame(LoadTest::COMPLETED, $done->fresh()->status, 'a finished test stays as it is');
    }

    public function test_a_namespace_runr_did_not_make_stays(): void
    {
        $this->k8sRoutes['GET '.self::NS] = fn () => [200, ['metadata' => ['name' => 'runr-loadtest', 'labels' => ['team' => 'qa']]]];

        $this->assertFalse(app(LoadTestRunner::class)->removeNamespace(new KubernetesClient($this->region->kubeconfig)));
        $this->assertSame([], $this->k8sCallsTo('DELETE', self::NS));
    }

    public function test_the_namespace_is_applied_again_after_it_was_removed(): void
    {
        $runner = app(LoadTestRunner::class);
        $k8s = new KubernetesClient($this->region->kubeconfig);
        $runner->ensureNamespace($k8s, $this->region);
        $runner->ensureNamespace($k8s, $this->region);
        $this->assertCount(1, array_filter($this->k8sCallsTo('PATCH', self::NS), fn ($c) => ($c['body']['kind'] ?? null) === 'Namespace'), 'remembered for ten minutes');

        $this->k8sRoutes['GET '.self::NS] = fn () => [200, ['metadata' => ['name' => 'runr-loadtest', 'labels' => [
            LoadTestManifests::LABEL_MANAGED => 'runr', LoadTestManifests::LABEL_COMPONENT => LoadTestManifests::COMPONENT,
        ]]]];
        $runner->removeNamespace($k8s);
        $runner->ensureNamespace($k8s, $this->region);

        $this->assertCount(2, array_filter($this->k8sCallsTo('PATCH', self::NS), fn ($c) => ($c['body']['kind'] ?? null) === 'Namespace'));
    }

    public function test_an_organizations_jobs_leave_the_namespace_when_its_share_ends(): void
    {
        $this->k8sRoutes['GET '.self::JOBS] = function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame('app.kubernetes.io/component=load-test,runr.dev/tenant=t-acme', $query['labelSelector'] ?? null);

            return [200, ['items' => [['metadata' => ['name' => 'lt-1']], ['metadata' => ['name' => 'lt-2']]]]];
        };

        $this->assertSame(2, app(LoadTestRunner::class)->removeOrganizationJobs(new KubernetesClient($this->region->kubeconfig), 't-acme'));

        foreach (['lt-1', 'lt-2'] as $name) {
            $this->assertCount(1, $this->k8sCallsTo('DELETE', self::JOBS.'/'.$name));
            $this->assertCount(1, $this->k8sCallsTo('DELETE', self::NS.'/configmaps/'.$name));
        }
    }

    public function test_tests_go_with_their_project(): void
    {
        $this->running(['status' => LoadTest::COMPLETED]);

        $this->project->delete();

        $this->assertSame(0, LoadTest::query()->count());
    }
}
