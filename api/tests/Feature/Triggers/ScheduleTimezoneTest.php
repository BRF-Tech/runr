<?php

namespace Tests\Feature\Triggers;

use App\Jobs\ApplyScheduleTimezonesJob;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\Setting;
use App\Models\User;
use App\Services\KnativeDeployService;
use App\Triggers\Gateway\GatewayConfig;
use App\Triggers\ProjectTriggers;
use App\Triggers\ScheduleTimezones;
use App\Triggers\Types\ScheduleTrigger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\Support\TimeZones;
use Tests\TestCase;

/**
 * Time zones of schedule triggers (docs/triggers.md#schedules-and-time-zones): config.timezone, or
 * the organization's zone (OrgTimezone) the schedule then follows — rendered into the CronJob's
 * spec.timeZone and the gateway's schedule source, and brought to a new organization zone at once
 * (ScheduleTimezones). Schedules from before keep UTC (migration 0090).
 */
class ScheduleTimezoneTest extends TestCase
{
    use ActsWithRoles;
    use BuildsTriggerFixtures;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->userWithRole('owner');
    }

    private function build(Project $project): array
    {
        return app(KnativeDeployService::class)->buildManifests($project->fresh(), $this->triggerVersion($project));
    }

    public function test_the_api_takes_an_iana_zone_and_presents_the_effective_one(): void
    {
        Setting::set('timezone', 'Europe/Berlin');
        $project = $this->triggerProject($this->triggerRegion());
        $as = $this->actingAs($this->owner, 'sanctum');
        $url = "/api/projects/{$project->id}/triggers";

        foreach (['Mars/Olympus', 'europe/berlin', 'UTC+3'] as $zone) {
            $as->postJson($url, ['type' => 'schedule', 'name' => 'bad', 'config' => ['cron' => '0 9 * * *', 'timezone' => $zone]])
                ->assertUnprocessable()->assertJsonValidationErrors('config.timezone');
        }

        $as->postJson($url, ['type' => 'schedule', 'name' => 'tokyo', 'config' => ['cron' => '0 9 * * *', 'timezone' => 'Asia/Tokyo']])
            ->assertCreated()
            ->assertJsonPath('data.config.timezone', 'Asia/Tokyo')
            ->assertJsonPath('data.effective_timezone', 'Asia/Tokyo')
            ->assertJsonPath('data.timezone_source', 'trigger');

        $as->postJson($url, ['type' => 'schedule', 'name' => 'office', 'config' => ['cron' => '0 9 * * 1-5', 'timezone' => '']])
            ->assertCreated()
            ->assertJsonPath('data.effective_timezone', 'Europe/Berlin')
            ->assertJsonPath('data.timezone_source', 'organization');
        $this->assertSame(['cron' => '0 9 * * 1-5'], $project->triggers()->where('name', 'office')->first()->config);

        // the organization's zone changes: the trigger follows it
        Setting::set('timezone', 'America/New_York');
        $as->getJson($url)->assertOk()->assertJsonPath('data.2.effective_timezone', 'America/New_York');

        // a backward-compatible name PHP knows (Europe/Kiev where this PHP has it: tests/Support/TimeZones)
        if ($backward = TimeZones::backward()) {
            $as->postJson($url, ['type' => 'schedule', 'name' => 'backward', 'config' => ['cron' => '0 9 * * *', 'timezone' => $backward]])
                ->assertCreated()->assertJsonPath('data.effective_timezone', $backward);
        }
        $as->postJson($url, ['type' => 'schedule', 'name' => 'kyiv', 'config' => ['cron' => '0 9 * * *', 'timezone' => 'Europe/Kyiv']])->assertCreated();
    }

    public function test_a_schedule_alone_is_a_cronjob_in_its_zone(): void
    {
        $region = $this->triggerRegion();
        $own = $this->triggerProject($region, 'schedule', 'berlin-cron');
        $trigger = $own->triggers()->first();
        ProjectTriggers::withoutSync(fn () => $trigger->forceFill(['config' => ['cron' => '*/5 * * * *', 'timezone' => 'Europe/Berlin']])->save());

        $cron = $this->manifestNamed($this->build($own), 'CronJob', 'berlin-cron-trigger');
        $this->assertSame('Europe/Berlin', $cron['spec']['timeZone']);
        $this->assertSame('*/5 * * * *', $cron['spec']['schedule']);
        $this->assertArrayNotHasKey(ScheduleTimezones::ANNOTATION, $cron['metadata']['annotations']);

        // without a zone of its own: the organization's, marked as following it
        Setting::set('timezone', 'Asia/Tokyo');
        $follows = $this->triggerProject($region, 'schedule', 'org-cron');
        $cron = $this->manifestNamed($this->build($follows), 'CronJob', 'org-cron-trigger');
        $this->assertSame('Asia/Tokyo', $cron['spec']['timeZone']);
        $this->assertSame(ScheduleTrigger::FROM_ORGANIZATION, $cron['metadata']['annotations'][ScheduleTimezones::ANNOTATION]);
    }

    public function test_a_schedule_next_to_http_gives_the_gateway_its_zone(): void
    {
        $project = $this->triggerProject($this->triggerRegion(), 'http', 'api-and-cron');
        $this->addTrigger($project, 'schedule', ['cron' => '0 3 * * *', 'timezone' => 'Europe/Berlin'], ['name' => 'nightly']);
        $this->addTrigger($project, 'schedule', ['cron' => '0 9 * * *'], ['name' => 'morning']);

        $secret = $this->manifestNamed($this->build($project), 'Secret', 'api-and-cron-qc-config');
        $config = json_decode(base64_decode($secret['data']['gateway.json']), true);
        [$nightly, $morning] = $config['triggers'];

        $this->assertSame(['cron' => '0 3 * * *', 'timezone' => 'Europe/Berlin'], $nightly['source']);
        $this->assertSame(['cron' => '0 9 * * *', 'timezone' => 'Europe/Istanbul', 'timezone_from' => 'organization'], $morning['source']);
    }

    public function test_a_new_organization_zone_reaches_the_deployed_schedules_that_follow_it(): void
    {
        $region = $this->triggerRegion();
        $alone = $this->triggerProject($region, 'schedule', 'org-cron');
        $mixed = $this->triggerProject($region, 'http', 'mixed');
        $this->addTrigger($mixed, 'schedule', ['cron' => '0 9 * * *'], ['name' => 'morning']);
        $this->addTrigger($mixed, 'schedule', ['cron' => '0 3 * * *', 'timezone' => 'UTC'], ['name' => 'nightly']);

        $gateway = [
            'version' => 1, 'sink' => 'http://mixed.ev.svc.cluster.local',
            'triggers' => [
                ['name' => 'morning', 'type' => 'schedule', 'kind' => 'schedule', 'source' => ['cron' => '0 9 * * *', 'timezone' => 'Europe/Istanbul', 'timezone_from' => 'organization']],
                ['name' => 'nightly', 'type' => 'schedule', 'kind' => 'schedule', 'source' => ['cron' => '0 3 * * *', 'timezone' => 'UTC']],
                ['name' => 'hooks', 'type' => 'webhook', 'kind' => 'inbox', 'source' => new \stdClass],
            ],
        ];
        $patches = [];
        $gone = false;
        Http::fake(function (Request $request) use ($gateway, &$patches, &$gone) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($gone) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }
            if ($request->method() === 'PATCH') {
                $patches[$path] = json_decode($request->body(), true);

                return Http::response([], 200);
            }

            return match (true) {
                str_ends_with($path, '/cronjobs/org-cron-trigger') => Http::response([
                    'metadata' => ['name' => 'org-cron-trigger', 'annotations' => [ScheduleTimezones::ANNOTATION => 'organization']],
                    'spec' => ['schedule' => '*/5 * * * *', 'timeZone' => 'Europe/Istanbul'],
                ]),
                str_ends_with($path, '/secrets/mixed-qc-config') => Http::response([
                    'metadata' => ['name' => 'mixed-qc-config'], 'data' => ['gateway.json' => base64_encode(GatewayConfig::encode($gateway))],
                ]),
                default => Http::response(['kind' => 'Status', 'code' => 404], 404),
            };
        });

        $changed = app(ScheduleTimezones::class)->apply('Asia/Tokyo');

        $this->assertSame(['ev/org-cron-trigger → Asia/Tokyo', 'ev/mixed-qc → Asia/Tokyo'], $changed);
        $this->assertSame(['spec' => ['timeZone' => 'Asia/Tokyo']], $patches['/apis/batch/v1/namespaces/ev/cronjobs/org-cron-trigger']);

        $json = base64_decode($patches['/api/v1/namespaces/ev/secrets/mixed-qc-config']['data']['gateway.json']);
        $written = json_decode($json, true);
        $this->assertSame('Asia/Tokyo', $written['triggers'][0]['source']['timezone']);
        $this->assertSame('UTC', $written['triggers'][1]['source']['timezone'], 'a schedule with its own zone keeps it');
        $this->assertStringContainsString('"source": {}', $json, 'an empty source stays an object');
        $this->assertSame(
            GatewayConfig::hash($json),
            $patches['/apis/apps/v1/namespaces/ev/deployments/mixed-qc']['spec']['template']['metadata']['annotations'][GatewayConfig::HASH_ANNOTATION],
            'the gateway pod rolls onto the new config',
        );

        // workloads that are gone (not deployed) change nothing
        $gone = true;
        $this->assertSame([], app(ScheduleTimezones::class)->apply('Asia/Tokyo'));
        $this->assertNotNull($alone);
    }

    public function test_changing_the_organization_zone_queues_the_update(): void
    {
        Queue::fake();
        $this->signedIn($this->owner)->patchJson('/api/settings', ['timezone' => 'Europe/Berlin'])->assertOk();
        Queue::assertPushed(ApplyScheduleTimezonesJob::class, 1);

        // the same zone again: nothing to do
        $this->signedIn($this->owner)->patchJson('/api/settings', ['timezone' => 'Europe/Berlin'])->assertOk();
        Queue::assertPushed(ApplyScheduleTimezonesJob::class, 1);
    }

    /**
     * The single-trigger fields of the project API (Terraform's runr_project, MCP project tools):
     * config.schedule_timezone sets the schedule trigger's zone, the config shows it and the zone
     * the schedule runs in.
     */
    public function test_the_project_api_sets_and_shows_the_schedule_zone(): void
    {
        Setting::set('timezone', 'Europe/Berlin');
        $region = $this->triggerRegion();
        $as = $this->actingAs($this->owner, 'sanctum');

        $created = $as->postJson('/api/projects', [
            'name' => 'nightly-job', 'service_type' => 'caas', 'knative_region_id' => $region->id,
            'config' => ['image' => 'nginx:1.27-alpine', 'trigger_type' => 'schedule', 'schedule' => '0 3 * * *', 'schedule_timezone' => 'Asia/Tokyo'],
        ])->assertCreated()
            ->assertJsonPath('config.schedule_timezone', 'Asia/Tokyo')
            ->assertJsonPath('config.schedule_effective_timezone', 'Asia/Tokyo');
        $id = $created->json('id');
        $this->assertSame(['cron' => '0 3 * * *', 'timezone' => 'Asia/Tokyo'], ProjectTrigger::query()->where('project_id', $id)->first()->config);

        // "" goes back to the organization's zone, which the schedule then follows
        $as->putJson("/api/projects/{$id}", ['config' => ['schedule_timezone' => '']])->assertOk()
            ->assertJsonPath('data.config.schedule_timezone', null)
            ->assertJsonPath('data.config.schedule_effective_timezone', 'Europe/Berlin');
        $this->assertSame(['cron' => '0 3 * * *'], ProjectTrigger::query()->where('project_id', $id)->first()->config);

        // a changed cron keeps the zone; an unknown zone is refused
        $as->putJson("/api/projects/{$id}", ['config' => ['schedule_timezone' => 'UTC']])->assertOk();
        $as->putJson("/api/projects/{$id}", ['config' => ['schedule' => '0 4 * * *']])->assertOk()
            ->assertJsonPath('data.config.schedule', '0 4 * * *')
            ->assertJsonPath('data.config.schedule_timezone', 'UTC');
        $as->putJson("/api/projects/{$id}", ['config' => ['schedule_timezone' => 'Mars/Olympus']])
            ->assertUnprocessable()->assertJsonValidationErrors('config.schedule_timezone');

        // a project without a schedule has neither
        $http = $this->triggerProject($region, 'http', 'web');
        $as->getJson("/api/projects/{$http->id}")->assertOk()
            ->assertJsonPath('data.config.schedule_timezone', null)
            ->assertJsonPath('data.config.schedule_effective_timezone', null);
    }

    public function test_the_upgrade_keeps_existing_schedules_in_utc(): void
    {
        $project = $this->triggerProject($this->triggerRegion(), 'schedule', 'old-cron');
        $this->addTrigger($project, 'schedule', ['cron' => '0 3 * * *', 'timezone' => 'Europe/Berlin'], ['name' => 'berlin']);

        (require base_path('database/migrations/2026_10_22_200001_pin_existing_schedule_triggers_to_utc.php'))->up();

        $rows = ProjectTrigger::query()->where('project_id', $project->id)->orderBy('position')->get();
        $this->assertSame(['cron' => '*/5 * * * *', 'timezone' => 'UTC'], $rows[0]->config);
        $this->assertSame('Europe/Berlin', $rows[1]->config['timezone']);
    }
}
