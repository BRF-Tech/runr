<?php

namespace Tests\Feature\Projects;

use App\Jobs\ApplyWarmScalesJob;
use App\Models\Deployment;
use App\Models\DeploymentRollout;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\Projects\WarmScaleService;
use App\Services\Rollouts\RolloutTraffic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The timed warmer on the cluster (docs/scaling.md#timed-warmer, WarmScaleService): the schedule's
 * value of the minute, in the organization's zone, goes onto the PodAutoscaler of the live
 * revision as autoscaling.knative.dev/min-scale — a merge patch of the PodAutoscaler only (never
 * the Revision or the Knative Service) before Knative 1.21 or without a recorded Serving version,
 * of the Revision only from Knative 1.21 on (its reconciler copies the Revision's value onto the
 * PodAutoscaler and undoes any other), and only when the value differs. The project records what
 * was applied, the live deployment's warm_log what it ran with (metering).
 *
 * Wednesday 2026-10-21 10:00 UTC = 13:00 in Istanbul (the default zone) = 06:00 in New York.
 */
class WarmScaleServiceTest extends TestCase
{
    use RefreshDatabase;

    private const PA_PATH = '/apis/autoscaling.internal.knative.dev/v1alpha1/namespaces/fn/podautoscalers';

    private const REVISION_PATH = '/apis/serving.knative.dev/v1/namespaces/fn/revisions';

    private const REVISION = 'shop-00001';

    /** @var list<array{method: string, path: string, body: array, type: string}> */
    private array $calls = [];

    /** min-scale annotation the fake PodAutoscaler has (null: none) */
    private ?string $current = '0';

    private int $patchStatus = 200;

    /** status.traffic of the fake Knative Service (null: none, the latest ready revision serves) */
    private ?array $traffic = null;

    /** Revisions with a PodAutoscaler */
    private array $autoscaled = [self::REVISION];

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'UTC'));

        $this->region = KnativeRegion::create([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'is_active' => true,
            'cluster_status' => 'online',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
        ]);

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = [
                'method' => $request->method(),
                'path' => $path,
                'body' => json_decode($request->body(), true) ?? [],
                'type' => (string) ($request->header('Content-Type')[0] ?? ''),
            ];

            if ($request->method() === 'GET' && str_ends_with($path, '/namespaces/fn/services')) {
                return Http::response(['items' => [
                    ['metadata' => ['name' => 'shop'], 'status' => array_filter([
                        'latestReadyRevisionName' => end($this->autoscaled), 'latestCreatedRevisionName' => end($this->autoscaled), 'traffic' => $this->traffic,
                    ])],
                ]]);
            }
            // the PodAutoscalers and the Revisions alike carry the min-scale $current
            $autoscaler = fn (string $name) => ['metadata' => ['name' => $name, 'annotations' => array_filter([WarmScaleService::ANNOTATION => $this->current])]];
            foreach ([self::PA_PATH, self::REVISION_PATH] as $collection) {
                if ($request->method() === 'GET' && str_ends_with($path, $collection)) {
                    return Http::response(['items' => array_map($autoscaler, $this->autoscaled)]);
                }
                foreach ($this->autoscaled as $name) {
                    if ($request->method() === 'GET' && str_ends_with($path, $collection.'/'.$name)) {
                        return Http::response($autoscaler($name));
                    }
                }
            }
            if ($request->method() === 'PATCH') {
                return Http::response(['kind' => 'PodAutoscaler'], $this->patchStatus);
            }

            return Http::response(['kind' => 'Status'], 404);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * An HTTP function with a warmer and its live deployment.
     *
     * @param  list<array{cron: string, min_scale: int}>|null  $schedule
     * @param  array<string, mixed>  $sizing
     * @return array{0: Project, 1: Deployment}
     */
    private function project(?array $schedule, array $sizing = [], int $minScale = 0): array
    {
        $project = Project::create(['name' => 'shop', 'service_type' => 'xaas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        XaasConfig::create([
            'project_id' => $project->id, 'filename' => 'main.py', 'handler' => 'handler', 'cpu' => '250m', 'memory_mb' => 128,
            'trigger_type' => 'http', 'scale_to_zero' => true, 'min_scale' => $minScale, 'max_scale' => 3, 'scale_schedule' => $schedule,
        ]);

        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active']);
        $deployment->forceFill(['ready_at' => now()->subHour(), 'sizing' => $sizing + [
            'region_id' => $this->region->id, 'cpu_m' => 250, 'memory_mb' => 128, 'trigger_type' => 'http',
            'min_scale' => 0, 'max_scale' => 3, 'scale_to_zero' => true, 'gpu_count' => 0,
        ]])->save();

        return [$project->fresh(), $deployment->fresh()];
    }

    /** @return list<array{method: string, path: string, body: array, type: string}> */
    private function patches(): array
    {
        return array_values(array_filter($this->calls, fn (array $c) => $c['method'] !== 'GET'));
    }

    public function test_a_rollout_keeps_every_revision_that_takes_traffic_warm(): void
    {
        // a canary at 10 %: the stable revision and the new one both serve (RolloutTraffic)
        $this->autoscaled = [self::REVISION, 'shop-00002'];
        $this->traffic = [
            ['revisionName' => self::REVISION, 'percent' => 90],
            ['revisionName' => 'shop-00002', 'percent' => 10, 'tag' => 'canary'],
        ];
        $this->project([['cron' => '* * * * *', 'min_scale' => 2]]);

        $this->assertSame(2, app(WarmScaleService::class)->run());
        $patched = array_map(fn (array $c) => basename($c['path']), $this->patches());
        sort($patched);
        $this->assertSame([self::REVISION, 'shop-00002'], $patched);
    }

    public function test_the_value_of_the_minute_goes_onto_the_live_revisions_podautoscaler(): void
    {
        [$project, $deployment] = $this->project([['cron' => '* * * * *', 'min_scale' => 2]]);

        $this->assertSame(1, app(WarmScaleService::class)->run());

        $patches = $this->patches();
        $this->assertCount(1, $patches, 'one write: the PodAutoscaler, never the Revision or the Knative Service');
        $this->assertSame(self::PA_PATH.'/'.self::REVISION, substr($patches[0]['path'], -strlen(self::PA_PATH.'/'.self::REVISION)));
        $this->assertSame('PATCH', $patches[0]['method']);
        $this->assertStringContainsString('application/merge-patch+json', $patches[0]['type']);
        $this->assertSame(['metadata' => ['annotations' => [WarmScaleService::ANNOTATION => '2']]], $patches[0]['body']);

        $project->refresh();
        $this->assertSame(2, $project->warm_scale_applied);
        $this->assertEquals(now(), $project->warm_scale_applied_at);
        $this->assertSame([[now()->getTimestamp(), 2]], $deployment->fresh()->sizing['warm_log']);
    }

    public function test_from_knative_1_21_on_the_value_goes_onto_the_revision(): void
    {
        $this->region->update(['knative_serving_version' => '1.24.0']);
        [$project, $deployment] = $this->project([['cron' => '* * * * *', 'min_scale' => 2]]);

        $this->assertSame(1, app(WarmScaleService::class)->run());

        $patches = $this->patches();
        $this->assertCount(1, $patches, 'one write: the Revision, the PodAutoscaler follows it');
        $this->assertStringEndsWith(self::REVISION_PATH.'/'.self::REVISION, $patches[0]['path']);
        $this->assertStringContainsString('application/merge-patch+json', $patches[0]['type']);
        $this->assertSame(['metadata' => ['annotations' => [WarmScaleService::ANNOTATION => '2']]], $patches[0]['body']);
        $this->assertFalse(collect($this->calls)->contains(fn (array $c) => str_contains($c['path'], '/podautoscalers')), 'the PodAutoscalers are not read either');
        $this->assertSame(2, $project->fresh()->warm_scale_applied);
        $this->assertSame([[now()->getTimestamp(), 2]], $deployment->fresh()->sizing['warm_log']);

        // the Revision has the value: nothing to patch
        $this->current = '2';
        $this->calls = [];
        $this->assertSame(0, app(WarmScaleService::class)->run());
        $this->assertSame([], $this->patches());
    }

    public function test_from_knative_1_21_on_a_deploy_and_a_schedule_turned_off_annotate_the_revision(): void
    {
        $this->region->update(['knative_serving_version' => 'v1.21.3']);
        [$project, $deployment] = $this->project([['cron' => '* * * * *', 'min_scale' => 3]]);

        app(WarmScaleService::class)->afterReady($project, $deployment, ['status' => ['latestReadyRevisionName' => self::REVISION]]);
        $this->assertStringEndsWith(self::REVISION_PATH.'/'.self::REVISION, $this->patches()[0]['path']);
        $this->assertSame('3', $this->patches()[0]['body']['metadata']['annotations'][WarmScaleService::ANNOTATION]);

        $this->current = '3';
        $this->calls = [];
        XaasConfig::query()->update(['scale_schedule' => null]);
        app(WarmScaleService::class)->run();
        $this->assertStringEndsWith(self::REVISION_PATH.'/'.self::REVISION, $this->patches()[0]['path']);
        $this->assertSame('0', $this->patches()[0]['body']['metadata']['annotations'][WarmScaleService::ANNOTATION], 'min_scale back');
        $this->assertNull($project->fresh()->warm_scale_applied);
    }

    public function test_the_holder_of_the_min_scale_follows_the_serving_version(): void
    {
        $revision = [WarmScaleService::REVISION_API, WarmScaleService::REVISION_KIND];
        $autoscaler = [WarmScaleService::PA_API, WarmScaleService::PA_KIND];
        foreach ([null => $autoscaler, '1.20.3' => $autoscaler, '1.3.0' => $autoscaler, '1.21.0' => $revision, 'v1.24.1' => $revision, '2.0' => $revision] as $version => $want) {
            $region = new KnativeRegion(['knative_serving_version' => $version === '' ? null : $version]);
            $this->assertSame($want, WarmScaleService::holder($region), "Knative {$version}");
        }
        $this->assertSame($autoscaler, WarmScaleService::holder(null));
    }

    public function test_a_rollout_keeps_the_previous_revision_warm_where_the_serving_version_takes_it(): void
    {
        [$project] = $this->project(null);
        $traffic = app(RolloutTraffic::class);

        $this->assertTrue($traffic->warm(new DeploymentRollout, $project->fresh('knativeRegion'), self::REVISION));
        $this->assertStringEndsWith(self::PA_PATH.'/'.self::REVISION, $this->patches()[0]['path'], 'before Knative 1.21');

        $this->region->update(['knative_serving_version' => '1.24.0']);
        $this->calls = [];
        $this->assertTrue($traffic->warm(new DeploymentRollout, $project->fresh('knativeRegion'), self::REVISION));
        $this->assertStringEndsWith(self::REVISION_PATH.'/'.self::REVISION, $this->patches()[0]['path'], 'from Knative 1.21 on');
        $this->assertSame(['metadata' => ['annotations' => [WarmScaleService::ANNOTATION => '1']]], $this->patches()[0]['body']);
    }

    public function test_nothing_is_patched_while_the_podautoscaler_has_the_value(): void
    {
        $this->current = '2';
        [$project] = $this->project([['cron' => '* * * * *', 'min_scale' => 2]]);

        $this->assertSame(0, app(WarmScaleService::class)->run());
        $this->assertSame([], $this->patches());
        $this->assertSame(2, $project->fresh()->warm_scale_applied);
    }

    public function test_the_crons_run_in_the_organization_zone(): void
    {
        // 09:00–17:00 one warm container: 13:00 in Istanbul, 06:00 in New York
        $schedule = [['cron' => '0 9 * * *', 'min_scale' => 1], ['cron' => '0 17 * * *', 'min_scale' => 0]];
        [$project] = $this->project($schedule);

        app(WarmScaleService::class)->run();
        $this->assertSame('1', $this->patches()[0]['body']['metadata']['annotations'][WarmScaleService::ANNOTATION] ?? null, 'Istanbul (default)');

        Setting::set('timezone', 'America/New_York');
        $this->current = '1';
        $this->calls = [];
        app(WarmScaleService::class)->run();
        $this->assertSame('0', $this->patches()[0]['body']['metadata']['annotations'][WarmScaleService::ANNOTATION] ?? null, 'New York');
        $this->assertSame(0, $project->fresh()->warm_scale_applied);
    }

    public function test_scale_to_zero_off_keeps_one_and_max_scale_caps(): void
    {
        [, $deployment] = $this->project([['cron' => '* * * * *', 'min_scale' => 0]], ['scale_to_zero' => false]);
        app(WarmScaleService::class)->run();
        $this->assertSame('1', $this->patches()[0]['body']['metadata']['annotations'][WarmScaleService::ANNOTATION]);

        $deployment->forceFill(['sizing' => array_merge($deployment->sizing, ['scale_to_zero' => true, 'max_scale' => 2])])->save();
        XaasConfig::query()->update(['scale_schedule' => json_encode([['cron' => '* * * * *', 'min_scale' => 5]])]);
        $this->calls = [];
        app(WarmScaleService::class)->run();
        $this->assertSame('2', $this->patches()[0]['body']['metadata']['annotations'][WarmScaleService::ANNOTATION]);
    }

    public function test_turning_the_warmer_off_gives_min_scale_back_once(): void
    {
        $this->current = '3';
        [$project, $deployment] = $this->project(null, minScale: 1);
        $project->forceFill(['warm_scale_applied' => 3, 'warm_scale_applied_at' => now()->subHour()])->saveQuietly();

        app(WarmScaleService::class)->run();

        $this->assertSame('1', $this->patches()[0]['body']['metadata']['annotations'][WarmScaleService::ANNOTATION]);
        $this->assertNull($project->fresh()->warm_scale_applied);
        $this->assertSame(1, $deployment->fresh()->sizing['warm_log'][0][1], 'metering sees min_scale again');

        // Released: no longer a candidate
        $this->calls = [];
        app(WarmScaleService::class)->run();
        $this->assertSame([], $this->calls);
    }

    public function test_offline_regions_and_stopped_projects_are_left_alone(): void
    {
        [$project] = $this->project([['cron' => '* * * * *', 'min_scale' => 2]]);

        $this->region->update(['cluster_status' => 'offline']);
        app(WarmScaleService::class)->run();
        $this->assertSame([], $this->calls);

        $this->region->update(['cluster_status' => 'online']);
        $project->forceFill(['stopped_at' => now()])->saveQuietly();
        app(WarmScaleService::class)->run();
        $this->assertSame([], $this->calls);
        $this->assertNull($project->fresh()->warm_scale_applied);
    }

    public function test_a_failed_patch_records_nothing(): void
    {
        $this->patchStatus = 500;
        [$project, $deployment] = $this->project([['cron' => '* * * * *', 'min_scale' => 2]]);

        $this->assertSame(0, app(WarmScaleService::class)->run());
        $this->assertNull($project->fresh()->warm_scale_applied);
        $this->assertArrayNotHasKey('warm_log', $deployment->fresh()->sizing);
    }

    public function test_after_a_deploy_the_new_revision_gets_the_value_of_now(): void
    {
        [$project, $deployment] = $this->project([['cron' => '* * * * *', 'min_scale' => 3]]);

        app(WarmScaleService::class)->afterReady($project, $deployment, ['status' => ['latestReadyRevisionName' => self::REVISION]]);

        $this->assertSame(['metadata' => ['annotations' => [WarmScaleService::ANNOTATION => '3']]], $this->patches()[0]['body']);
        $this->assertSame(3, $project->fresh()->warm_scale_applied);
    }

    public function test_after_a_deploy_without_a_warmer_the_applied_value_is_cleared(): void
    {
        [$project, $deployment] = $this->project(null);
        $project->forceFill(['warm_scale_applied' => 2, 'warm_scale_applied_at' => now()])->saveQuietly();

        app(WarmScaleService::class)->afterReady($project, $deployment, ['status' => ['latestReadyRevisionName' => self::REVISION]]);

        $this->assertSame([], $this->calls, 'the new revision runs with its template');
        $this->assertNull($project->fresh()->warm_scale_applied);
    }

    public function test_the_scheduled_job_applies_the_warmers(): void
    {
        $this->project([['cron' => '* * * * *', 'min_scale' => 1]]);

        $this->assertSame(1, (new ApplyWarmScalesJob)->handle(app(WarmScaleService::class)));
        $this->artisan('projects:warm')->expectsOutputToContain('PodAutoscalers patched')->assertSuccessful();
    }

    public function test_metering_reads_the_warm_log(): void
    {
        $eight = Carbon::parse('2026-10-21 08:00:00')->getTimestamp();
        $nine = Carbon::parse('2026-10-21 09:00:00')->getTimestamp();
        [, $deployment] = $this->project(null, ['warm_log' => [[$nine, 0], [$eight, 2]]]);
        $log = WarmScaleService::logOf($deployment);
        $this->assertSame([[$eight, 2], [$nine, 0]], $log, 'oldest first');

        $from = Carbon::parse('2026-10-21 07:00:00');
        $to = Carbon::parse('2026-10-21 10:00:00');
        $this->assertSame([
            [$from->getTimestamp(), $eight, 0],
            [$eight, $nine, 2],
            [$nine, $to->getTimestamp(), 0],
        ], WarmScaleService::appliedSegments($log, $deployment, $from, $to));

        $this->assertSame(2, WarmScaleService::appliedAt($log, $deployment, Carbon::parse('2026-10-21 08:30:00')));
        $this->assertSame(0, WarmScaleService::appliedAt($log, $deployment, Carbon::parse('2026-10-21 07:30:00')), 'before the log: as deployed');
        $this->assertNull(WarmScaleService::logOf(new Deployment), 'no warm_log: metered as before');
    }
}
