<?php

namespace Tests\Feature\Triggers;

use App\Jobs\DropCdcSlotsJob;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\User;
use App\Triggers\Cleanup\CdcCleanupPod;
use App\Triggers\Cleanup\CdcSlotCleanup;
use App\Triggers\Gateway\GatewayConfig;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\TestCase;

/**
 * What a cdc trigger leaves on its database when it goes (docs/triggers.md#cdc-cleanup): its
 * replication slots and the publication RunR created, dropped by DropCdcSlotsJob once no deployed
 * event gateway reads them, in the region (CdcCleanupPod), with a notification of the outcome.
 */
class CdcSlotCleanupTest extends TestCase
{
    use ActsWithRoles;
    use BuildsTriggerFixtures;
    use RefreshDatabase;

    private const DSN = 'postgres://repl:s3cret@db.example:5432/shop';

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->userWithRole('owner');
        $this->project = $this->triggerProject($this->triggerRegion(redis: false), 'http', 'shop');
    }

    private function url(string $suffix = ''): string
    {
        return "/api/projects/{$this->project->id}/triggers{$suffix}";
    }

    private function addCdc(string $name = 'orders', array $config = []): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson($this->url(), ['type' => 'cdc', 'name' => $name, 'config' => $config + [
            'publication' => 'runr_orders', 'create_publication' => true, 'tables' => ['public.orders'],
        ], 'secrets' => ['dsn' => self::DSN]])->assertCreated();
    }

    public function test_removing_a_cdc_trigger_schedules_the_drop_of_its_slot_and_publication(): void
    {
        Queue::fake();
        $this->addCdc();
        Queue::assertNothingPushed();

        $this->actingAs($this->owner, 'sanctum')->deleteJson($this->url('/orders'))
            ->assertOk()
            ->assertJsonPath('cleanup.0.trigger', 'orders')
            ->assertJsonPath('cleanup.0.slots', ['runr_shop_orders'])
            ->assertJsonPath('cleanup.0.publication', 'runr_orders')
            ->assertJsonPath('cleanup.0.status', 'scheduled');

        Queue::assertPushed(DropCdcSlotsJob::class, function (DropCdcSlotsJob $job) {
            return $job->spec === ['dsn' => self::DSN, 'slots' => ['runr_shop_orders'], 'publication' => 'runr_orders', 'drop_publication' => true]
                && $job->reason === 'removed' && $job->initiatorId === (string) $this->owner->id
                && $job->podLabels['runr/project-id'] === $this->project->id
                && $job->workloads === [['shop', (string) $this->project->knative_region_id]];
        });
        // the DSN travels encrypted
        $this->assertContains(ShouldBeEncrypted::class, class_implements(DropCdcSlotsJob::class));
    }

    public function test_a_list_without_the_trigger_a_rename_and_a_new_slot_leave_old_slots_to_drop(): void
    {
        Queue::fake();
        $this->addCdc();
        $as = $this->actingAs($this->owner, 'sanctum');

        // settings that keep the slot: nothing to drop
        $as->patchJson($this->url('/orders'), ['config' => ['publication' => 'runr_orders', 'create_publication' => true, 'tables' => ['public.orders'], 'operations' => ['insert']]])->assertOk()->assertJsonMissingPath('cleanup');
        Queue::assertNothingPushed();

        // a rename: the default slot follows the name
        $as->patchJson($this->url('/orders'), ['name' => 'orders-cdc'])->assertOk()->assertJsonPath('cleanup.0.slots', ['runr_shop_orders']);
        Queue::assertPushed(DropCdcSlotsJob::class, fn (DropCdcSlotsJob $j) => $j->spec['slots'] === ['runr_shop_orders'] && ! $j->spec['drop_publication'] && $j->reason === 'changed');

        // its own slot
        $as->patchJson($this->url('/orders-cdc'), ['config' => ['publication' => 'runr_orders', 'create_publication' => true, 'tables' => ['public.orders'], 'slot' => 'orders_slot']])->assertOk();
        Queue::assertPushed(DropCdcSlotsJob::class, fn (DropCdcSlotsJob $j) => $j->spec['slots'] === ['runr_shop_orders_cdc']);

        // the whole list without it (runr.yaml, PUT)
        $as->putJson($this->url(), ['triggers' => [['type' => 'http']]])->assertOk()->assertJsonPath('cleanup.0.slots', ['orders_slot']);
        Queue::assertPushed(DropCdcSlotsJob::class, fn (DropCdcSlotsJob $j) => $j->spec['slots'] === ['orders_slot'] && $j->spec['drop_publication'] && $j->reason === 'removed');
        Queue::assertPushed(DropCdcSlotsJob::class, 3);
    }

    public function test_deleting_the_project_drops_its_slots(): void
    {
        Queue::fake();
        $this->addCdc();
        Http::fake(fn () => Http::response(['kind' => 'Status', 'code' => 404], 404));

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/projects/{$this->project->id}")->assertNoContent();

        Queue::assertPushed(DropCdcSlotsJob::class, fn (DropCdcSlotsJob $j) => $j->reason === 'project_deleted' && $j->spec['slots'] === ['runr_shop_orders']);
    }

    /** @return array{0: DropCdcSlotsJob, 1: KnativeRegion} */
    private function job(array $slots = ['runr_shop_orders'], int $runs = 0, ?string $deadline = null): array
    {
        $region = $this->project->knativeRegion;

        return [new DropCdcSlotsJob(
            null, $this->project->id, 'shop', (string) $region->id, ['runr/project-id' => $this->project->id],
            [['shop', (string) $region->id]], 'orders',
            ['dsn' => self::DSN, 'slots' => $slots, 'publication' => 'runr_orders', 'drop_publication' => true],
            'removed', (string) $this->owner->id, $runs, $deadline,
        ), $region];
    }

    private function fakePod(?array $result, ?string $error = null): object
    {
        $pod = new class($result, $error) extends CdcCleanupPod
        {
            public array $runs = [];

            public function __construct(private ?array $result, private ?string $error) {}

            public function run(KnativeRegion $region, array $labels, array $spec, int $timeout = 180): array
            {
                $this->runs[] = $spec;

                return ['result' => $this->result, 'error' => $this->error, 'phase' => 'Succeeded'];
            }
        };
        $this->app->instance(CdcCleanupPod::class, $pod);

        return $pod;
    }

    /** The gateway of shop still lists the slot. */
    private function fakeDeployedGateway(): void
    {
        $gateway = ['version' => 1, 'triggers' => [['name' => 'orders', 'kind' => 'cdc', 'source' => ['slot' => 'runr_shop_orders', 'dsn' => self::DSN]]]];
        Http::fake(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/secrets/shop-qc-config')
            ? Http::response(['data' => ['gateway.json' => base64_encode(GatewayConfig::encode($gateway))]])
            : Http::response([], 404));
    }

    public function test_the_job_waits_while_a_deployed_gateway_reads_the_slot(): void
    {
        config(['queue.default' => 'database']); // a queue that can wait
        Queue::fake();
        $this->fakeDeployedGateway();
        $pod = $this->fakePod(null);

        [$job] = $this->job();
        $this->app->call([$job, 'handle']);

        $this->assertSame([], $pod->runs, 'nothing dropped while the gateway reads it');
        Queue::assertPushed(DropCdcSlotsJob::class, fn (DropCdcSlotsJob $next) => $next->runs === 0 && $next->deadline === $job->deadline && $next->delay !== null);

        // past the deadline: reported with the SQL to run by hand
        [$late] = $this->job(deadline: now()->subMinute()->toIso8601String());
        $this->app->call([$late, 'handle']);
        $note = Notification::query()->where('user_id', $this->owner->id)->latest('id')->first();
        $this->assertSame('critical', $note->level);
        $this->assertStringContainsString("SELECT pg_drop_replication_slot('runr_shop_orders');", $note->body);
        $this->assertStringContainsString('DROP PUBLICATION IF EXISTS "runr_orders";', $note->body);
        $this->assertStringNotContainsString('s3cret', $note->body.json_encode($note->data));
    }

    public function test_the_job_drops_the_slots_and_notifies(): void
    {
        Queue::fake();
        Http::fake(fn () => Http::response([], 404)); // no gateway deployed
        $pod = $this->fakePod(['database' => 'db.example/shop', 'reachable' => true, 'slots' => [['name' => 'runr_shop_orders', 'status' => 'dropped']], 'publication' => ['name' => 'runr_orders', 'status' => 'dropped']]);

        [$job] = $this->job();
        $this->app->call([$job, 'handle']);

        $this->assertSame([['slots' => ['runr_shop_orders'], 'dsn' => self::DSN, 'publication' => 'runr_orders', 'drop_publication' => true]], $pod->runs);
        $note = Notification::query()->where('user_id', $this->owner->id)->latest('id')->first();
        $this->assertSame('info', $note->level);
        $this->assertStringContainsString('runr_shop_orders dropped', $note->body);
        Queue::assertNothingPushed();
    }

    /** A queue that runs jobs at once cannot wait for the next deploy: the user is told at once. */
    public function test_a_sync_queue_reports_instead_of_waiting(): void
    {
        $this->fakeDeployedGateway();
        $pod = $this->fakePod(null);

        [$job] = $this->job();
        $this->app->call([$job, 'handle']);

        $this->assertSame([], $pod->runs);
        $note = Notification::query()->where('user_id', $this->owner->id)->latest('id')->first();
        $this->assertSame('critical', $note->level);
        $this->assertStringContainsString('The event gateway of shop still reads the slot', $note->body);
        $this->assertStringContainsString("pg_drop_replication_slot('runr_shop_orders')", $note->body);
    }

    public function test_an_unreachable_database_is_tried_again_then_reported(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        Http::fake(fn () => Http::response([], 404));
        $this->fakePod(['database' => 'db.example/shop', 'reachable' => false, 'error' => 'connect: dial tcp: i/o timeout', 'slots' => null]);

        [$first] = $this->job();
        $this->app->call([$first, 'handle']);
        Queue::assertPushed(DropCdcSlotsJob::class, fn (DropCdcSlotsJob $next) => $next->runs === 1);
        $this->assertSame(0, Notification::query()->count());

        [$last] = $this->job(runs: DropCdcSlotsJob::MAX_RUNS - 1);
        $this->app->call([$last, 'handle']);
        $note = Notification::query()->where('user_id', $this->owner->id)->latest('id')->first();
        $this->assertSame('critical', $note->level);
        $this->assertStringContainsString('could not reach the database db.example/shop', $note->body);
        $this->assertStringContainsString("pg_drop_replication_slot('runr_shop_orders')", $note->body);
    }

    public function test_a_slot_another_trigger_reads_is_left_alone(): void
    {
        Queue::fake();
        Http::fake(fn () => Http::response([], 404));
        $pod = $this->fakePod(['reachable' => true, 'slots' => []]);
        // the trigger was added again under the same name: its default slot is in use again
        ProjectTrigger::query()->create(['project_id' => $this->project->id, 'name' => 'orders', 'type' => 'cdc', 'config' => ['publication' => 'runr_orders'], 'secrets' => ['dsn' => self::DSN], 'enabled' => true, 'position' => 1]);
        $this->assertSame(['runr_shop_orders' => true], app(CdcSlotCleanup::class)->wantedSlots());

        [$job] = $this->job();
        $job->spec['drop_publication'] = false;
        $this->app->call([$job, 'handle']);

        $this->assertSame([], $pod->runs);
    }

    public function test_the_pod_runs_the_gateway_image_with_the_dsn_in_a_secret(): void
    {
        Sleep::fake();
        $applied = [];
        $deleted = [];
        Http::fake(function (Request $r) use (&$applied, &$deleted) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if ($r->method() === 'DELETE') {
                $deleted[] = $path;

                return Http::response([], 200);
            }
            if (in_array($r->method(), ['POST', 'PUT', 'PATCH'], true)) {
                $applied[] = json_decode($r->body(), true);

                return Http::response([], 201);
            }
            if (str_ends_with($path, '/log')) {
                return Http::response("starting\nRUNR_RESULT {\"database\":\"db.example/shop\",\"reachable\":true,\"slots\":[{\"name\":\"runr_shop_orders\",\"status\":\"dropped\"}]}\n");
            }

            return Http::response(['status' => ['phase' => 'Succeeded']]);
        });

        $out = (new CdcCleanupPod)->run($this->project->knativeRegion, ['runr/project-id' => 'p1'], ['dsn' => self::DSN, 'slots' => ['runr_shop_orders'], 'publication' => '', 'drop_publication' => false]);

        $this->assertSame('dropped', $out['result']['slots'][0]['status']);
        $this->assertNull($out['error']);
        $secret = collect($applied)->firstWhere('kind', 'Secret');
        $pod = collect($applied)->firstWhere('kind', 'Pod');
        $this->assertSame(self::DSN, json_decode(base64_decode($secret['data']['cleanup.json']), true)['dsn']);
        $this->assertStringNotContainsString('s3cret', json_encode($pod), 'the DSN is never in the pod spec');
        $this->assertSame('p1', $pod['metadata']['labels']['runr/project-id']);
        $this->assertSame('cdc-cleanup', $pod['spec']['containers'][0]['env'][0]['value']);
        $this->assertStringContainsString('queue-consumer', $pod['spec']['containers'][0]['image']);
        $this->assertCount(2, $deleted, 'pod and Secret removed afterwards');
    }
}
