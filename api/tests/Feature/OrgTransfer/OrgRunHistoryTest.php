<?php

namespace Tests\Feature\OrgTransfer;

use App\Models\BindingUsageRecord;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\ImportedHistoryRecord;
use App\Models\KnativeRegion;
use App\Models\OrgTransfer;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\TriggerEvent;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\Version;
use App\Services\GitService;
use App\Services\Metering\UsageAggregator;
use App\Services\OrgTransfer\ImportChoices;
use App\Services\OrgTransfer\OrgExporter;
use App\Services\OrgTransfer\OrgImporter;
use App\Services\OrgTransfer\OrgTransferPaths;
use App\Services\OrgTransfer\Package\PackageReader;
use App\Services\OrgTransfer\Sections\HistorySection;
use App\Services\OrgTransfer\Sections\SectionRegistry;
use App\Workflows\Engine\Retention;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use App\Workflows\Models\WorkflowUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Run history in an organization package (docs/org-export.md#run-history): with include_history
 * the export carries every deployment, the workflow runs RunR keeps (with their steps), the
 * stored trigger events and the usage records; the import lands them as history that never acts —
 * deployments in a final state and not metered, runs in a final state the engine leaves alone,
 * trigger events that are not delivered on their own, usage read-only in imported_history — and
 * drops what the target's retention would prune at once.
 */
class OrgRunHistoryTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private User $owner;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        config(['org_transfer.compression' => 'none', 'secrets.storage' => 'database']);
        Queue::fake();
        SectionRegistry::reset();

        $this->owner = $this->userWithRole('owner');
        $this->actingAs($this->owner, 'sanctum');
        $this->region = KnativeRegion::create(['name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'is_demo' => true]);
    }

    /** A project with two versions, deployments, workflow runs, trigger events and usage. */
    private function seedHistory(): Project
    {
        $project = Project::create(['name' => 'api', 'service_type' => 'faas', 'runtime' => 'python', 'created_by' => (string) $this->owner->id]);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30]);
        $git = app(GitService::class);
        $git->commitFiles($project, ['main.py' => 'v1'], 'first', (string) $this->owner->id);
        $git->commitFiles($project, ['main.py' => 'v2'], 'second', (string) $this->owner->id);
        [$v1, $v2] = Version::query()->where('project_id', $project->id)->orderBy('number')->get()->all();

        $old = Deployment::create(['project_id' => $project->id, 'version_id' => $v1->id, 'status' => 'shut_down', 'deployed_by' => 'ada', 'ready_at' => now()->subDays(3)]);
        $old->forceFill(['created_at' => now()->subDays(3), 'ended_at' => now()->subDays(2)])->saveQuietly();
        $failed = Deployment::create(['project_id' => $project->id, 'version_id' => $v2->id, 'status' => 'failed', 'deployed_by' => 'ada', 'knative_message' => 'image pull failed']);
        $failed->forceFill(['created_at' => now()->subDays(2), 'ended_at' => now()->subDays(2)])->saveQuietly();
        $active = Deployment::create(['project_id' => $project->id, 'version_id' => $v2->id, 'status' => 'active', 'deployed_by' => 'ada', 'ready_at' => now()->subDay(), 'knative_ready' => true]);
        $active->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $workflow = Workflow::create(['project_id' => $project->id, 'name' => 'checkout', 'triggers' => [], 'config' => [], 'source' => 'manifest']);
        $done = WorkflowRun::create(['workflow_id' => $workflow->id, 'project_id' => $project->id, 'status' => 'completed', 'trigger_source' => 'api',
            'event' => ['name' => 'order.created', 'data' => ['id' => 7]], 'output' => ['ok' => true], 'started_at' => now()->subHours(3), 'ended_at' => now()->subHours(2), 'steps_count' => 1]);
        WorkflowStep::create(['run_id' => $done->id, 'step_key' => 'charge', 'name' => 'charge', 'type' => 'run', 'status' => 'completed', 'position' => 0, 'attempt' => 1, 'max_attempts' => 3,
            'input' => ['amount' => 5], 'output' => ['charged' => 5], 'attempts' => [['attempt' => 1, 'duration_ms' => 12]]]);
        WorkflowRun::create(['workflow_id' => $workflow->id, 'project_id' => $project->id, 'status' => 'failed', 'trigger_source' => 'api',
            'error' => ['message' => 'boom'], 'started_at' => now()->subDays(40), 'ended_at' => now()->subDays(40)]);
        $sleeping = WorkflowRun::create(['workflow_id' => $workflow->id, 'project_id' => $project->id, 'status' => 'sleeping', 'trigger_source' => 'event',
            'replay_of_run_id' => $done->id, 'lease_token' => 'lease', 'lease_until' => now()->addMinute(), 'next_attempt_at' => now()->addHour(), 'started_at' => now()->subHour(), 'steps_count' => 1]);
        WorkflowStep::create(['run_id' => $sleeping->id, 'step_key' => 'wait', 'name' => 'wait', 'type' => 'sleep', 'status' => 'sleeping', 'position' => 0, 'wake_at' => now()->addHour()]);

        $trigger = ProjectTrigger::create(['project_id' => $project->id, 'name' => 'hooks', 'type' => 'webhook', 'config' => ['verify' => 'none', 'retention_days' => 7], 'enabled' => true, 'position' => 0, 'ingress_key' => 'abcdefgh12345678']);
        foreach ([['delivered', 1, 'd-1'], ['pending', 1, 'd-2'], ['delivered', 10, 'd-3']] as [$status, $daysAgo, $delivery]) {
            TriggerEvent::create(['project_id' => $project->id, 'trigger_id' => $trigger->id, 'kind' => 'webhook', 'event_type' => 'dev.runr.webhook.received',
                'delivery_id' => $delivery, 'content_type' => 'application/json', 'headers' => ['X-Event' => 'push'], 'body' => base64_encode('{"n":1}'), 'body_size' => 7,
                'status' => $status, 'attempts' => 1, 'received_at' => now()->subDays($daysAgo), 'expires_at' => now()->subDays($daysAgo)->addDays(7)]);
        }

        UsageRecord::create(['project_id' => $project->id, 'deployment_id' => $active->id, 'region_id' => $this->region->id, 'record_date' => now()->subDay()->toDateString(),
            'scale_mode' => 'scale_to_zero', 'entry_point' => 'http', 'session_count' => 12, 'total_cpu_mc_ms' => 4000]);
        WorkflowUsage::create(['date' => now()->subDay()->toDateString(), 'project_id' => $project->id, 'workflow_id' => $workflow->id, 'step_executions' => 3, 'runs_started' => 2]);
        BindingUsageRecord::create(['binding_id' => strtolower((string) Str::ulid()), 'binding_name' => 'cache', 'type' => 'kv', 'plan' => 'shared', 'record_date' => now()->subDay()->toDateString(), 'storage_gb_hours' => 1.5]);
        // a platform copy of a tenant's usage is not this organization's
        UsageRecord::create(['tenant_id' => 'acme', 'project_id' => $project->id, 'deployment_id' => $active->id, 'region_id' => $this->region->id, 'record_date' => now()->subDay()->toDateString(), 'scale_mode' => 'scale_to_zero', 'entry_point' => 'http']);

        return $project;
    }

    private function export(array $options): OrgTransfer
    {
        $transfer = OrgTransfer::query()->create(['owner_key' => 'platform', 'kind' => 'export', 'status' => 'queued', 'options' => $options, 'destination' => ['type' => 'download'], 'created_by' => (string) $this->owner->id]);
        app(OrgExporter::class)->run($transfer, null);

        return $transfer->fresh();
    }

    private function importOf(OrgTransfer $export, array $choices): OrgTransfer
    {
        $import = OrgTransfer::query()->create(['owner_key' => 'platform', 'kind' => 'import', 'status' => 'verifying', 'upload_size' => $export->package_size, 'uploaded_bytes' => $export->package_size, 'created_by' => (string) $this->owner->id]);
        OrgTransferPaths::ensure(OrgTransferPaths::dir($import));
        copy(OrgTransferPaths::package($export), OrgTransferPaths::package($import));
        app(OrgImporter::class)->verify($import, ImportChoices::from($choices));

        return $import->fresh();
    }

    private function apply(OrgTransfer $import, array $choices): OrgTransfer
    {
        $import->update(['status' => OrgTransfer::STATUS_APPLYING]);
        app(OrgImporter::class)->apply($import, ImportChoices::from($choices, ImportChoices::from((array) $import->options)), $this->owner);

        return $import->fresh();
    }

    public function test_without_the_option_the_package_has_no_history(): void
    {
        $this->seedHistory();
        $export = $this->export([]);

        $paths = array_column((new PackageReader)->manifest(OrgTransferPaths::package($export))['entries'], 'path');
        $this->assertNotContains(HistorySection::summaryEntry(), $paths);
    }

    public function test_the_export_carries_every_deployment_run_event_and_usage_record(): void
    {
        $project = $this->seedHistory();
        $export = $this->export(['include_history' => true]);
        $this->assertSame('completed', $export->status, (string) $export->message);

        $manifest = (new PackageReader)->manifest(OrgTransferPaths::package($export));
        $this->assertSame(3, $manifest['contents']['history_deployments']);
        $this->assertSame(3, $manifest['contents']['history_workflow_runs']);
        $this->assertSame(3, $manifest['contents']['history_trigger_events']);
        $this->assertSame(3, $manifest['contents']['history_usage_records'], 'usage, workflow usage and binding usage — not the platform copy');
        $this->assertTrue($manifest['options']['include_history']);

        $dir = OrgTransferPaths::dir($export).'/check';
        (new PackageReader)->extract(OrgTransferPaths::package($export), $dir);
        $records = iterator_to_array(HistorySection::lines($dir.'/'.HistorySection::projectEntry($project->id)), false);
        $run = collect($records)->first(fn ($r) => $r['kind'] === 'workflow_run' && $r['status'] === 'completed');
        $this->assertSame('checkout', $run['workflow']);
        $this->assertSame(['ok' => true], $run['output']);
        $this->assertSame(['amount' => 5], $run['steps'][0]['input']);
        $live = collect($records)->first(fn ($r) => $r['kind'] === 'deployment' && $r['status'] === 'active');
        $this->assertTrue($live['live']);
        $this->assertNotNull($live['ended_at'], 'a live deployment ends at the export for its history');
        $event = collect($records)->first(fn ($r) => $r['kind'] === 'trigger_event' && $r['delivery_id'] === 'd-2');
        $this->assertSame(['name' => 'hooks', 'type' => 'webhook', 'retention_days' => 7], $event['trigger']);
        $this->assertSame(base64_encode('{"n":1}'), $event['body']);
    }

    public function test_the_import_lands_history_that_never_acts(): void
    {
        $this->seedHistory();
        $export = $this->export(['include_history' => true]);
        $import = $this->importOf($export, ['renames' => [Project::query()->value('id') => 'api-copy']]);
        $this->assertSame('planned', $import->status, (string) $import->message);

        $history = $import->plan['history'];
        $this->assertTrue($history['import']);
        $this->assertSame(3, $history['usage_records']);
        $this->assertSame(['workflow_runs' => 1, 'trigger_events' => 1], $history['projects'][0]['dropped_by_retention']);

        $import = $this->apply($import, []);
        $this->assertSame('completed', $import->status, json_encode($import->report));
        $copy = Project::query()->where('slug', 'api-copy')->firstOrFail();

        // Deployments: final states, imported, never live, never metered
        $deployments = Deployment::query()->where('project_id', $copy->id)->orderBy('created_at')->get();
        $this->assertCount(3, $deployments);
        $this->assertSame(['shut_down', 'failed', 'shut_down'], $deployments->pluck('status')->all());
        $this->assertTrue($deployments->every(fn (Deployment $d) => $d->imported_at !== null && $d->ended_at !== null && ! $d->knative_ready));
        $this->assertSame([1, 2, 2], $deployments->map(fn ($d) => Version::query()->find($d->version_id)->number)->all());
        $this->assertSame('image pull failed', $deployments[1]->knative_message);
        $this->assertSame((string) $deployments->last()->id, (string) $copy->latestDeployment()->first()->id, 'ids sort by their time');
        $this->assertNull($copy->activeDeployment()->first());
        app(UsageAggregator::class)->aggregate(now()->subDay()->toDateString());
        app(UsageAggregator::class)->aggregate(now()->toDateString());
        $this->assertSame(0, UsageRecord::query()->where('project_id', $copy->id)->count(), 'imported deployments are not metered');

        // Workflow runs: the one past the retention is left out; the sleeping one is cancelled
        $runs = WorkflowRun::query()->where('project_id', $copy->id)->orderBy('created_at')->get();
        $this->assertSame(['completed', 'cancelled'], $runs->pluck('status')->all());
        $this->assertSame('{"ok":true}', json_encode($runs[0]->output));
        $this->assertSame((string) $runs[0]->id, (string) $runs[1]->replay_of_run_id, 'links point into the import');
        $this->assertNull($runs[1]->lease_until);
        $this->assertNull($runs[1]->next_attempt_at);
        $this->assertSame('cancelled', WorkflowStep::query()->where('run_id', $runs[1]->id)->value('status'));
        $this->assertSame('{"amount":5}', json_encode(WorkflowStep::query()->where('run_id', $runs[0]->id)->first()->input));
        $this->assertSame(0, WorkflowUsage::query()->where('project_id', $copy->id)->count(), 'not workflow usage');
        app(Retention::class)->prune();
        $this->assertSame(2, WorkflowRun::query()->where('project_id', $copy->id)->count(), 'nothing for the retention to prune');

        // Trigger events: within the retention, an undelivered one failed (replay delivers it)
        $events = TriggerEvent::query()->where('project_id', $copy->id)->orderBy('received_at')->get();
        $this->assertSame(['d-1', 'd-2'], $events->pluck('delivery_id')->all());
        $this->assertSame(['delivered', 'failed'], $events->pluck('status')->all());
        $this->assertSame(__('org_transfer.import.event_not_delivered'), $events[1]->last_error);
        $this->assertSame(ProjectTrigger::query()->where('project_id', $copy->id)->value('id'), $events[0]->trigger_id);

        // Usage: read-only history, the metering tables untouched
        $usage = ImportedHistoryRecord::query()->where('org_transfer_id', $import->id)->where('kind', 'usage')->get();
        $this->assertSame(['binding_usage_records', 'usage_records', 'workflow_usage'], $usage->pluck('source_table')->sort()->values()->all());
        $this->assertSame((string) $copy->id, (string) $usage->firstWhere('source_table', 'usage_records')->project_id);
        $this->assertSame(12, $usage->firstWhere('source_table', 'usage_records')->data['session_count']);
        $this->getJson("/api/org-transfers/{$import->id}/history?kind=usage")->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.kind', 'usage');

        // The report: one entry per kind and project
        $items = collect($import->report['items'])->where('section', 'history');
        $this->assertSame(4, $items->count());
        $this->assertTrue($items->every(fn ($i) => $i['status'] === 'created'));

        // Applied again: nothing twice
        $again = $this->apply($import, []);
        $this->assertSame(3, Deployment::query()->where('project_id', $copy->id)->count());
        $this->assertSame(3, ImportedHistoryRecord::query()->where('org_transfer_id', $again->id)->count());
    }

    public function test_metering_never_counts_an_imported_deployment(): void
    {
        $region = KnativeRegion::create(['name' => 'eu-2', 'project_namespace' => 'eu-2', 'is_active' => true]);
        $project = Project::create(['name' => 'always-on', 'service_type' => 'faas', 'runtime' => 'python', 'created_by' => (string) $this->owner->id]);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30, 'min_scale' => 1]);
        app(GitService::class)->commitFiles($project, ['main.py' => 'v1'], 'first', (string) $this->owner->id);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => Version::query()->where('project_id', $project->id)->value('id'), 'status' => 'shut_down', 'ready_at' => now()->startOfDay()->addHour()]);
        $deployment->forceFill(['created_at' => now()->startOfDay(), 'ended_at' => now()->startOfDay()->addHours(3), 'imported_at' => now()])->saveQuietly();

        app(UsageAggregator::class)->aggregate(now()->toDateString());
        $this->assertSame(0, UsageRecord::query()->where('project_id', $project->id)->count(), 'ran in another organization');

        // The same deployment run here is metered: the check above is not vacuous
        $deployment->forceFill(['imported_at' => null])->saveQuietly();
        app(UsageAggregator::class)->aggregate(now()->toDateString());
        $this->assertGreaterThan(0, UsageRecord::query()->where('project_id', $project->id)->count());
    }

    public function test_the_history_choice_off_imports_none_and_warns(): void
    {
        $this->seedHistory();
        $export = $this->export(['include_history' => true]);
        $import = $this->importOf($export, ['renames' => [Project::query()->value('id') => 'api-copy'], 'history' => false]);

        $this->assertFalse($import->plan['history']['import']);
        $this->assertContains('history_not_imported', array_column($import->plan['issues'], 'code'));

        $this->apply($import, ['history' => false]);
        $copy = Project::query()->where('slug', 'api-copy')->firstOrFail();
        $this->assertSame(0, Deployment::query()->where('project_id', $copy->id)->count());
        $this->assertSame(0, WorkflowRun::query()->where('project_id', $copy->id)->count());
        $this->assertSame(0, ImportedHistoryRecord::query()->count());
    }

    public function test_another_owner_cannot_read_an_imports_history(): void
    {
        $import = OrgTransfer::query()->create(['owner_key' => 'other-tenant', 'kind' => 'import', 'status' => 'completed']);

        $this->getJson("/api/org-transfers/{$import->id}/history")->assertNotFound();
        $this->actingAs($this->userWithRole('admin'), 'sanctum');
        $this->getJson("/api/org-transfers/{$import->id}/history")->assertForbidden();
    }
}
