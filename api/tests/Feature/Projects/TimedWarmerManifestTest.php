<?php

namespace Tests\Feature\Projects;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\KnativeDeployService;
use App\Services\Projects\WarmScaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A deploy renders the timed warmer's value of the moment, in the organization's zone, into the
 * Knative Service template (autoscaling.knative.dev/min-scale): the new revision starts right, and
 * the warmer moves it on the revision's PodAutoscaler from then on (docs/scaling.md#timed-warmer).
 * Previews never get a warmer. 2026-10-21 10:00 UTC is 13:00 in Istanbul, 06:00 in New York.
 */
class TimedWarmerManifestTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICE = [
        ['cron' => '0 9 * * 1-5', 'min_scale' => 3],
        ['cron' => '0 18 * * 1-5', 'min_scale' => 0],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'UTC'));
        ExecutorImage::create(['runtime' => 'python', 'version' => '3.14', 'docker_image' => 'ghcr.io/brf-tech/runr/exec-python:3.14',
            'is_default' => true, 'is_active' => true, 'sort_order' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function project(array $config): Project
    {
        $region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn', 'is_active' => true]);
        $project = Project::create(['name' => 'shop', 'service_type' => 'xaas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        XaasConfig::create($config + [
            'project_id' => $project->id, 'filename' => 'main.py', 'handler' => 'handler', 'cpu' => '250m', 'memory_mb' => 128,
            'trigger_type' => 'http', 'scale_to_zero' => true, 'min_scale' => 0, 'max_scale' => 5,
        ]);

        return $project->fresh();
    }

    /** @return array<string, string> */
    private function templateAnnotations(Project $project, array $deployConfig = []): array
    {
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $service = collect(app(KnativeDeployService::class)->buildManifests($project, $version, $deployConfig))->firstWhere('kind', 'Service');

        return $service['spec']['template']['metadata']['annotations'];
    }

    public function test_the_template_starts_with_the_value_of_the_moment(): void
    {
        $annotations = $this->templateAnnotations($this->project(['scale_schedule' => self::OFFICE, 'min_scale' => 1]));

        $this->assertSame('3', $annotations[WarmScaleService::ANNOTATION], 'Wednesday 13:00 in Istanbul: the schedule replaces min_scale');
        $this->assertSame('5', $annotations['autoscaling.knative.dev/max-scale']);
    }

    public function test_the_value_follows_the_organization_zone(): void
    {
        Setting::set('timezone', 'America/New_York');

        $annotations = $this->templateAnnotations($this->project(['scale_schedule' => self::OFFICE, 'min_scale' => 1]));

        $this->assertArrayNotHasKey(WarmScaleService::ANNOTATION, $annotations, '06:00 in New York: nothing warm yet');
    }

    public function test_scale_to_zero_off_keeps_one(): void
    {
        Setting::set('timezone', 'America/New_York');

        $this->assertSame('1', $this->templateAnnotations($this->project(['scale_schedule' => self::OFFICE, 'scale_to_zero' => false]))[WarmScaleService::ANNOTATION]);
    }

    public function test_max_scale_caps_the_value(): void
    {
        $this->assertSame('2', $this->templateAnnotations($this->project(['scale_schedule' => self::OFFICE]), ['max_scale' => 2])[WarmScaleService::ANNOTATION]);
    }

    public function test_a_deploy_override_turns_the_warmer_off_for_previews(): void
    {
        $annotations = $this->templateAnnotations($this->project(['scale_schedule' => self::OFFICE]), ['scale_to_zero' => true, 'min_scale' => 0, 'scale_schedule' => null]);

        $this->assertArrayNotHasKey(WarmScaleService::ANNOTATION, $annotations);
    }

    public function test_the_deploy_starts_the_warm_log_with_the_rendered_value(): void
    {
        $project = $this->project(['scale_schedule' => self::OFFICE]);
        $project->knativeRegion->update(['is_demo' => true]); // applied without a cluster
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = $project->deployments()->create(['version_id' => $version->id, 'status' => 'pending']);

        app(KnativeDeployService::class)->deploy($project->fresh(), $version, $deployment);

        $sizing = $deployment->fresh()->sizing;
        $this->assertSame(3, $sizing['min_scale'], 'sized with the peak (admission)');
        $this->assertSame([[now()->getTimestamp(), 3]], $sizing['warm_log'], 'metered from what the template got');
    }

    public function test_without_a_warmer_min_scale_renders_as_before(): void
    {
        $this->assertSame('2', $this->templateAnnotations($this->project(['min_scale' => 2]))[WarmScaleService::ANNOTATION]);
    }
}
