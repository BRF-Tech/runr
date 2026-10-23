<?php

namespace App\Services\OrgTransfer\Sections;

use App\Models\BindingUsageRecord;
use App\Models\Deployment;
use App\Models\ImportedHistoryRecord;
use App\Models\ProjectEnvironment;
use App\Models\ProjectTrigger;
use App\Models\TriggerEvent;
use App\Models\UsageRecord;
use App\Models\Version;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Triggers\Types\InboxTriggerType;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use App\Workflows\Models\WorkflowUsage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The run history of the exported projects (docs/org-export.md#run-history), with the export
 * option include_history and the import choice history:
 *
 *   extensions/history/history.json        what is in it: counts per project, the source's retention
 *   extensions/history/<project id>.jsonl  one record per line: every deployment, every workflow run
 *                                          RunR still keeps (with its steps: input, output, error,
 *                                          attempts) and the stored trigger events (the inbox)
 *   extensions/history/usage.jsonl         the organization's usage records of the exported projects
 *                                          and bindings, and its workflow usage — quantities, no money
 *
 * Import rules — history never acts:
 *  - deployments land in `deployments` with imported_at, in a final state (a live one becomes
 *    shut_down at the export's time), on the imported version of the same number; never live,
 *    never metered (UsageAggregator skips them), no config snapshot (a rollback to one deploys its
 *    version with the config of now). A deployment of an environment the target does not have,
 *    or of a version that was not imported, is left out
 *  - workflow runs land in `workflow_runs` / `workflow_steps` of the imported workflow of the same
 *    name, in a final state (a run still active in the source becomes cancelled), without leases
 *    or wake-ups: the engine never picks them up, and they do not count as workflow usage. Runs
 *    that ended before the target's run retention are left out (they would be pruned at once)
 *  - trigger events land in the inbox of the imported trigger of the same name with the target's
 *    retention from their arrival; events past it are left out; one the source had not delivered
 *    becomes failed — replay it to deliver it here, nothing is delivered on its own
 *  - usage records go to imported_history (read-only, GET /api/org-transfers/{id}/history),
 *    never into usage_records: nothing is charged twice. Bills: the Billing module's section
 *    (billing_history), the same way
 * Each kind of each project is one transaction and one report entry, so an apply again resumes.
 */
class HistorySection implements TransferSection
{
    public const KEY = 'history';

    private const CHUNK = 200;

    public function key(): string
    {
        return self::KEY;
    }

    public static function dir(): string
    {
        return PackageFormat::extensionDir(self::KEY);
    }

    public static function summaryEntry(): string
    {
        return self::dir().'/history.json';
    }

    public static function projectEntry(string $projectId): string
    {
        return self::dir().'/'.PackageFormat::segment($projectId).'.jsonl';
    }

    public static function usageEntry(): string
    {
        return self::dir().'/usage.jsonl';
    }

    // ── Export ───────────────────────────────────────────────────────────

    public function export(ExportContext $context): void
    {
        if (empty($context->options['include_history'])) {
            return;
        }

        $exportedAt = now();
        $summary = [
            'exported_at' => $exportedAt->toIso8601String(),
            'retention' => [
                'workflow_runs_days' => (int) config('workflows.retention.runs_days', 30),
                'trigger_events_days' => (int) config('triggers.event_retention_days', 7),
            ],
            'projects' => [],
            'usage' => 0,
        ];

        $projects = $context->projects()->values();
        foreach ($projects as $i => $project) {
            $context->progress('history', 85, 86, $i, $projects->count(), __('org_transfer.progress.history', ['name' => $project->name]));
            $out = fopen($context->path(self::projectEntry((string) $project->id)), 'wb');
            if ($out === false) {
                throw new \RuntimeException('Cannot write the history of '.$project->name);
            }
            try {
                $counts = [
                    'deployments' => $this->exportDeployments($out, (string) $project->id, $exportedAt),
                    'workflow_runs' => $this->exportRuns($out, (string) $project->id),
                    'trigger_events' => $this->exportEvents($out, (string) $project->id),
                ];
            } finally {
                fclose($out);
            }
            $summary['projects'][(string) $project->id] = $counts + ['name' => $project->name];
            foreach ($counts as $kind => $n) {
                $context->count('history_'.$kind, $n);
            }
        }

        $summary['usage'] = $this->exportUsage($context, $projects->pluck('id')->map(fn ($id) => (string) $id)->all());
        $context->count('history_usage_records', $summary['usage']);
        $context->putJson(self::summaryEntry(), $summary);
    }

    /** @param resource $out */
    private function exportDeployments($out, string $projectId, Carbon $exportedAt): int
    {
        $versions = Version::query()->where('project_id', $projectId)->pluck('number', 'id');
        $environments = Schema::hasTable('project_environments')
            ? ProjectEnvironment::query()->where('project_id', $projectId)->pluck('name', 'id') : collect();
        $n = 0;
        Deployment::query()->where('project_id', $projectId)->orderBy('created_at')->orderBy('id')
            ->chunk(self::CHUNK, function ($rows) use ($out, $versions, $environments, $exportedAt, &$n) {
                foreach ($rows as $d) {
                    /** @var Deployment $d */
                    $environment = $d->getAttribute('environment_id');
                    self::line($out, [
                        'kind' => 'deployment',
                        'id' => (string) $d->id,
                        'version' => $versions[$d->version_id] ?? null,
                        'environment' => $environment !== null ? ($environments[$environment] ?? null) : null,
                        'status' => $d->status,
                        'live' => in_array($d->status, Deployment::LIVE, true),
                        'deployed_by' => $d->deployed_by,
                        'knative_message' => $d->knative_message,
                        'runtime_image' => $d->runtime_image,
                        'config_source' => $d->getAttribute('config_source'),
                        'created_at' => $d->created_at?->toIso8601String(),
                        'ready_at' => $d->ready_at?->toIso8601String(),
                        // still running at the export: it ended there for this history
                        'ended_at' => ($d->ended_at ?? (in_array($d->status, Deployment::LIVE, true) ? $exportedAt : null))?->toIso8601String(),
                    ]);
                    $n++;
                }
            });

        return $n;
    }

    /** @param resource $out */
    private function exportRuns($out, string $projectId): int
    {
        if (! Schema::hasTable('workflow_runs')) {
            return 0;
        }
        $workflows = Workflow::query()->where('project_id', $projectId)->pluck('name', 'id');
        $n = 0;
        WorkflowRun::query()->where('project_id', $projectId)->orderBy('created_at')->orderBy('id')
            ->chunk(self::CHUNK, function ($runs) use ($out, $workflows, &$n) {
                $steps = WorkflowStep::query()->whereIn('run_id', $runs->modelKeys())->orderBy('position')->get()->groupBy('run_id');
                foreach ($runs as $run) {
                    /** @var WorkflowRun $run */
                    self::line($out, [
                        'kind' => 'workflow_run',
                        'id' => (string) $run->id,
                        'workflow' => $workflows[$run->workflow_id] ?? null,
                        'status' => $run->status,
                        'trigger_source' => $run->trigger_source,
                        'event' => self::json($run, 'event'),
                        'output' => self::json($run, 'output'),
                        'error' => self::json($run, 'error'),
                        'attempt' => (int) $run->attempt,
                        'retry_count' => (int) $run->retry_count,
                        'idempotency_key' => $run->idempotency_key,
                        'concurrency_key' => $run->concurrency_key,
                        'parent_run_id' => $run->parent_run_id,
                        'replay_of_run_id' => $run->replay_of_run_id,
                        'replay_from_step' => $run->replay_from_step,
                        'started_by' => $run->started_by,
                        'started_at' => $run->started_at?->toIso8601String(),
                        'ended_at' => $run->ended_at?->toIso8601String(),
                        'created_at' => $run->created_at?->toIso8601String(),
                        'steps' => ($steps[$run->id] ?? collect())->map(fn (WorkflowStep $s) => [
                            'step_key' => $s->step_key,
                            'name' => $s->name,
                            'type' => $s->type,
                            'status' => $s->status,
                            'position' => (int) $s->position,
                            'attempt' => (int) $s->attempt,
                            'max_attempts' => (int) $s->max_attempts,
                            'input' => self::json($s, 'input'),
                            'output' => self::json($s, 'output'),
                            'error' => self::json($s, 'error'),
                            'attempts' => self::json($s, 'attempts'),
                            'wait_event' => $s->wait_event,
                            'child_run_id' => $s->child_run_id,
                            'started_at' => $s->started_at?->toIso8601String(),
                            'ended_at' => $s->ended_at?->toIso8601String(),
                            'duration_ms' => $s->duration_ms,
                            'created_at' => $s->created_at?->toIso8601String(),
                        ])->values()->all(),
                    ]);
                    $n++;
                }
            });

        return $n;
    }

    /** @param resource $out */
    private function exportEvents($out, string $projectId): int
    {
        if (! Schema::hasTable('trigger_events')) {
            return 0;
        }
        $triggers = ProjectTrigger::query()->where('project_id', $projectId)->get()->keyBy('id');
        $n = 0;
        TriggerEvent::query()->where('project_id', $projectId)->orderBy('received_at')->orderBy('id')
            ->chunk(self::CHUNK, function ($events) use ($out, $triggers, &$n) {
                foreach ($events as $event) {
                    /** @var TriggerEvent $event */
                    $trigger = $triggers[$event->trigger_id] ?? null;
                    if ($trigger === null) {
                        continue;
                    }
                    self::line($out, [
                        'kind' => 'trigger_event',
                        'id' => (string) $event->id,
                        'trigger' => ['name' => $trigger->name, 'type' => $trigger->type, 'retention_days' => InboxTriggerType::retentionDays($trigger)],
                        'event_kind' => $event->kind,
                        'event_type' => $event->event_type,
                        'subject' => $event->subject,
                        'delivery_id' => $event->delivery_id,
                        'content_type' => $event->content_type,
                        'headers' => $event->headers,
                        'body' => $event->getRawOriginal('body'),
                        'body_size' => (int) $event->body_size,
                        'status' => $event->status,
                        'attempts' => (int) $event->attempts,
                        'replays' => (int) $event->replays,
                        'last_error' => $event->last_error,
                        'received_at' => $event->received_at?->toIso8601String(),
                        'delivered_at' => $event->delivered_at?->toIso8601String(),
                    ]);
                    $n++;
                }
            });

        return $n;
    }

    /**
     * The organization's usage records as metered: of the exported projects — of every project,
     * also deleted ones and model services, when the whole organization is exported — its
     * bindings' and its workflow usage.
     *
     * @param  list<string>  $projectIds
     */
    private function exportUsage(ExportContext $context, array $projectIds): int
    {
        $out = fopen($context->path(self::usageEntry()), 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot write the usage history');
        }
        $n = 0;
        $write = function (string $table, Model $row, ?string $projectId, ?string $date, ?string $subject) use ($out, &$n) {
            $data = $row->getAttributes();
            unset($data['tenant_id']);
            self::line($out, ['table' => $table, 'project_id' => $projectId, 'date' => $date, 'subject' => $subject, 'data' => $data]);
            $n++;
        };

        try {
            $all = ! is_array($context->options['projects'] ?? null);
            $names = $context->projects()->pluck('name', 'id');
            // The central tables also hold the platform's copies of tenants' usage (tenant_id):
            // the platform's books, not this organization's
            $own = fn (string $table) => fn ($q) => Schema::hasColumn($table, 'tenant_id') ? $q->whereNull('tenant_id') : $q;
            if (Schema::hasTable('usage_records')) {
                UsageRecord::query()->tap($own('usage_records'))->when(! $all, fn ($q) => $q->whereIn('project_id', $projectIds))->orderBy('record_date')->orderBy('id')
                    ->chunk(500, fn ($rows) => $rows->each(fn (UsageRecord $r) => $write('usage_records', $r, $r->getAttribute('project_id') !== null ? (string) $r->project_id : null,
                        Carbon::parse((string) $r->getRawOriginal('record_date'))->toDateString(), $names[$r->project_id] ?? null)));
            }
            if (Schema::hasTable('binding_usage_records')) {
                // Bindings travel whole (BindingsSection): their usage too, also of deleted ones
                BindingUsageRecord::query()->tap($own('binding_usage_records'))->orderBy('record_date')->orderBy('id')
                    ->chunk(500, fn ($rows) => $rows->each(fn (BindingUsageRecord $r) => $write('binding_usage_records', $r, null,
                        Carbon::parse((string) $r->getRawOriginal('record_date'))->toDateString(), (string) $r->getAttribute('binding_name'))));
            }
            if (Schema::hasTable('workflow_usage')) {
                $workflows = Workflow::query()->when(! $all, fn ($q) => $q->whereIn('project_id', $projectIds))->pluck('name', 'id');
                WorkflowUsage::query()->when(! $all, fn ($q) => $q->whereIn('project_id', $projectIds))->orderBy('date')->orderBy('id')
                    ->chunk(500, fn ($rows) => $rows->each(fn (WorkflowUsage $r) => $write('workflow_usage', $r, (string) $r->project_id,
                        Carbon::parse((string) $r->getRawOriginal('date'))->toDateString(), $workflows[$r->workflow_id] ?? null)));
            }
        } finally {
            fclose($out);
        }

        return $n;
    }

    // ── Plan ─────────────────────────────────────────────────────────────

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        $summary = $context->json(self::summaryEntry());
        if (! is_array($summary)) {
            return;
        }

        $runCutoff = now()->subDays((int) config('workflows.retention.runs_days', 30));
        $projects = [];
        foreach ((array) ($summary['projects'] ?? []) as $sourceId => $counts) {
            if ($context->skipsProject((string) $sourceId)) {
                continue;
            }
            $dropped = ['workflow_runs' => 0, 'trigger_events' => 0];
            foreach ($this->records($context, (string) $sourceId) as $record) {
                if (($record['kind'] ?? null) === 'workflow_run' && ! $this->runKept($record, $runCutoff)) {
                    $dropped['workflow_runs']++;
                } elseif (($record['kind'] ?? null) === 'trigger_event' && self::eventExpiry($record, (int) ($record['trigger']['retention_days'] ?? 0))->lte(now())) {
                    $dropped['trigger_events']++;
                }
            }
            $projects[] = [
                'source_id' => (string) $sourceId,
                'name' => $counts['name'] ?? null,
                'deployments' => (int) ($counts['deployments'] ?? 0),
                'workflow_runs' => (int) ($counts['workflow_runs'] ?? 0),
                'trigger_events' => (int) ($counts['trigger_events'] ?? 0),
                'dropped_by_retention' => $dropped,
            ];
        }

        $plan->history = array_merge($plan->history ?? [], [
            'import' => $context->choices->history,
            'exported_at' => $summary['exported_at'] ?? null,
            'projects' => $projects,
            'usage_records' => (int) ($summary['usage'] ?? 0),
        ]);
        if (! $context->choices->history) {
            $plan->generalIssue(ImportPlan::WARNING, 'history_not_imported', __('org_transfer.plan.history_not_imported'));
        }
    }

    // ── Import ───────────────────────────────────────────────────────────

    public function import(ImportContext $context): void
    {
        $summary = $context->json(self::summaryEntry());
        if (! is_array($summary) || ! $context->choices->history) {
            return;
        }
        $exportedAt = isset($summary['exported_at']) ? Carbon::parse((string) $summary['exported_at']) : now();

        $sources = array_keys((array) ($summary['projects'] ?? []));
        foreach ($sources as $i => $sourceId) {
            $sourceId = (string) $sourceId;
            $targetId = $context->mapped('project', $sourceId);
            if ($targetId === null) {
                continue;
            }
            $name = (string) ($summary['projects'][$sourceId]['name'] ?? $sourceId);
            $context->progress('history', 91, 92, $i, count($sources), __('org_transfer.progress.history', ['name' => $name]));

            foreach (['deployments', 'workflow_runs', 'trigger_events'] as $kind) {
                $key = "{$sourceId}:{$kind}";
                if ($context->settled(self::KEY, $key)) {
                    continue;
                }
                try {
                    $result = match ($kind) {
                        'deployments' => $this->importDeployments($context, $sourceId, $targetId, $exportedAt),
                        'workflow_runs' => $this->importRuns($context, $sourceId, $targetId, $exportedAt),
                        default => $this->importEvents($context, $sourceId, $targetId),
                    };
                    $context->record(self::KEY, $key, "{$name}: ".str_replace('_', ' ', $kind), 'created', $targetId,
                        __('org_transfer.import.history_counts', $result));
                } catch (\Throwable $e) {
                    $context->record(self::KEY, $key, "{$name}: ".str_replace('_', ' ', $kind), 'failed', null, $e->getMessage());
                }
            }
        }

        if (! $context->settled(self::KEY, 'usage') && $context->exists(self::usageEntry())) {
            try {
                $n = $this->importUsage($context);
                $context->record(self::KEY, 'usage', __('org_transfer.import.history_usage'), 'created', null, __('org_transfer.import.history_counts', ['imported' => $n, 'skipped' => 0]));
            } catch (\Throwable $e) {
                $context->record(self::KEY, 'usage', __('org_transfer.import.history_usage'), 'failed', null, $e->getMessage());
            }
        }
    }

    /** @return array{imported: int, skipped: int} */
    private function importDeployments(ImportContext $context, string $sourceId, string $targetId, Carbon $exportedAt): array
    {
        $versions = Version::query()->where('project_id', $targetId)->pluck('id', 'number');
        $environments = Schema::hasTable('project_environments')
            ? ProjectEnvironment::query()->where('project_id', $targetId)->pluck('id', 'name') : collect();
        $hasEnvironment = Schema::hasColumn('deployments', 'environment_id');
        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        $skipped = 0;
        foreach ($this->records($context, $sourceId) as $d) {
            if (($d['kind'] ?? null) !== 'deployment') {
                continue;
            }
            $version = $versions[(int) ($d['version'] ?? 0)] ?? null;
            $environment = $d['environment'] ?? null;
            $environmentId = $environment !== null ? ($environments[$environment] ?? null) : null;
            if ($version === null || ($environment !== null && ($environmentId === null || ! $hasEnvironment))) {
                $skipped++;

                continue;
            }
            $created = self::time($d['created_at'] ?? null) ?? $exportedAt;
            $ended = self::time($d['ended_at'] ?? null) ?? ($d['live'] ?? false ? $exportedAt : $created);
            $rows[] = array_filter([
                'id' => self::ulidAt($created),
                'project_id' => $targetId,
                'version_id' => $version,
                // a final state: what ran in the source is history here
                'status' => ($d['status'] ?? null) === 'failed' ? 'failed' : 'shut_down',
                'deployed_by' => $d['deployed_by'] ?? null,
                'knative_ready' => false,
                'knative_message' => $d['knative_message'] ?? null,
                'runtime_image' => $d['runtime_image'] ?? null,
                'config_source' => $d['config_source'] ?? null,
                'environment_id' => $environmentId,
                'ready_at' => self::stamp(self::time($d['ready_at'] ?? null)),
                'ended_at' => self::stamp($ended),
                'imported_at' => $now,
                'created_at' => self::stamp($created),
                'updated_at' => self::stamp($ended),
            ], fn ($v) => $v !== null);
        }

        $connection = (new Deployment)->getConnection();
        $connection->transaction(function () use ($connection, $rows) {
            foreach ($rows as $row) {
                // column sets differ (nullable columns left out): one insert per row
                $connection->table('deployments')->insert($row);
            }
        });

        return ['imported' => count($rows), 'skipped' => $skipped];
    }

    /** @return array{imported: int, skipped: int} */
    private function importRuns(ImportContext $context, string $sourceId, string $targetId, Carbon $exportedAt): array
    {
        if (! Schema::hasTable('workflow_runs')) {
            return ['imported' => 0, 'skipped' => 0];
        }
        $workflows = Workflow::query()->where('project_id', $targetId)->pluck('id', 'name');
        $cutoff = now()->subDays((int) config('workflows.retention.runs_days', 30));

        // New ids first: parents, replays and children point at runs of the same import
        $ids = [];
        foreach ($this->records($context, $sourceId) as $run) {
            if (($run['kind'] ?? null) === 'workflow_run' && isset($workflows[$run['workflow'] ?? '']) && $this->runKept($run, $cutoff)) {
                $ids[(string) $run['id']] = self::ulidAt(self::time($run['created_at'] ?? null) ?? $exportedAt);
            }
        }

        $skipped = 0;
        $imported = 0;
        $connection = (new WorkflowRun)->getConnection();
        $connection->transaction(function () use ($context, $sourceId, $targetId, $workflows, $ids, $exportedAt, $connection, &$skipped, &$imported) {
            foreach ($this->records($context, $sourceId) as $run) {
                if (($run['kind'] ?? null) !== 'workflow_run') {
                    continue;
                }
                $id = $ids[(string) ($run['id'] ?? '')] ?? null;
                if ($id === null) {
                    $skipped++;

                    continue;
                }
                $active = ! in_array($run['status'] ?? null, WorkflowRun::TERMINAL, true);
                $ended = self::time($run['ended_at'] ?? null) ?? $exportedAt;
                $created = self::time($run['created_at'] ?? null) ?? $exportedAt;
                $steps = (array) ($run['steps'] ?? []);
                $connection->table('workflow_runs')->insert([
                    'id' => $id,
                    'workflow_id' => $workflows[$run['workflow']],
                    'project_id' => $targetId,
                    'status' => $active ? WorkflowRun::CANCELLED : $run['status'],
                    'trigger_source' => in_array($run['trigger_source'] ?? null, WorkflowRun::SOURCES, true) ? $run['trigger_source'] : 'api',
                    'event' => self::encode($run['event'] ?? null),
                    'output' => self::encode($run['output'] ?? null),
                    'error' => self::encode($active ? ['message' => __('org_transfer.import.run_active')] : ($run['error'] ?? null)),
                    'attempt' => (int) ($run['attempt'] ?? 0),
                    'retry_count' => (int) ($run['retry_count'] ?? 0),
                    'idempotency_key' => $run['idempotency_key'] ?? null,
                    'concurrency_key' => $run['concurrency_key'] ?? null,
                    'parent_run_id' => $ids[(string) ($run['parent_run_id'] ?? '')] ?? null,
                    'replay_of_run_id' => $ids[(string) ($run['replay_of_run_id'] ?? '')] ?? null,
                    'replay_from_step' => $run['replay_from_step'] ?? null,
                    'started_by' => $run['started_by'] ?? null,
                    'started_at' => self::stamp(self::time($run['started_at'] ?? null)),
                    'ended_at' => self::stamp($ended),
                    'steps_count' => count($steps),
                    'created_at' => self::stamp($created),
                    'updated_at' => self::stamp($ended),
                ]);
                foreach ($steps as $step) {
                    $stepActive = in_array($step['status'] ?? null, WorkflowStep::OPEN, true) || ($step['status'] ?? null) === 'running';
                    $connection->table('workflow_steps')->insert([
                        'id' => strtolower((string) Str::ulid()),
                        'run_id' => $id,
                        'step_key' => (string) ($step['step_key'] ?? ''),
                        'name' => (string) ($step['name'] ?? ''),
                        'type' => (string) ($step['type'] ?? 'run'),
                        'status' => $stepActive ? 'cancelled' : $step['status'],
                        'position' => (int) ($step['position'] ?? 0),
                        'attempt' => (int) ($step['attempt'] ?? 0),
                        'max_attempts' => max(1, (int) ($step['max_attempts'] ?? 1)),
                        'input' => self::encode($step['input'] ?? null),
                        'output' => self::encode($step['output'] ?? null),
                        'error' => self::encode($step['error'] ?? null),
                        'attempts' => self::encode($step['attempts'] ?? null),
                        'wait_event' => $step['wait_event'] ?? null,
                        'child_run_id' => $ids[(string) ($step['child_run_id'] ?? '')] ?? null,
                        'started_at' => self::stamp(self::time($step['started_at'] ?? null)),
                        'ended_at' => self::stamp(self::time($step['ended_at'] ?? null) ?? ($stepActive ? $ended : null)),
                        'duration_ms' => $step['duration_ms'] ?? null,
                        'created_at' => self::stamp(self::time($step['created_at'] ?? null) ?? $created),
                        'updated_at' => self::stamp($ended),
                    ]);
                }
                $imported++;
            }
        });

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /** @return array{imported: int, skipped: int} */
    private function importEvents(ImportContext $context, string $sourceId, string $targetId): array
    {
        if (! Schema::hasTable('trigger_events')) {
            return ['imported' => 0, 'skipped' => 0];
        }
        $triggers = ProjectTrigger::query()->where('project_id', $targetId)->get()->keyBy(fn (ProjectTrigger $t) => $t->name.'|'.$t->type);
        $now = now();
        $skipped = 0;
        $imported = 0;
        $connection = (new TriggerEvent)->getConnection();
        $connection->transaction(function () use ($context, $sourceId, $targetId, $triggers, $now, $connection, &$skipped, &$imported) {
            foreach ($this->records($context, $sourceId) as $event) {
                if (($event['kind'] ?? null) !== 'trigger_event') {
                    continue;
                }
                $trigger = $triggers[($event['trigger']['name'] ?? '').'|'.($event['trigger']['type'] ?? '')] ?? null;
                $expires = $trigger ? self::eventExpiry($event, InboxTriggerType::retentionDays($trigger)) : null;
                if ($trigger === null || $expires->lte($now)) {
                    $skipped++;

                    continue;
                }
                $delivered = in_array($event['status'] ?? null, [TriggerEvent::DELIVERED, TriggerEvent::FAILED], true);
                $received = self::time($event['received_at'] ?? null) ?? $now;
                $inserted = $connection->table('trigger_events')->insertOrIgnore([
                    'id' => self::ulidAt($received),
                    'project_id' => $targetId,
                    'trigger_id' => (string) $trigger->id,
                    'kind' => in_array($event['event_kind'] ?? null, [TriggerEvent::KIND_WEBHOOK, TriggerEvent::KIND_PLATFORM], true) ? $event['event_kind'] : TriggerEvent::KIND_WEBHOOK,
                    'event_type' => mb_substr((string) ($event['event_type'] ?? ''), 0, 128),
                    'subject' => $event['subject'] ?? null,
                    'delivery_id' => $event['delivery_id'] ?? null,
                    'content_type' => $event['content_type'] ?? null,
                    'headers' => self::encode($event['headers'] ?? null),
                    'body' => $event['body'] ?? null,
                    'body_size' => (int) ($event['body_size'] ?? 0),
                    // nothing is delivered on its own: an event the source had not delivered is
                    // failed here — a replay delivers it
                    'status' => $delivered ? $event['status'] : TriggerEvent::FAILED,
                    'attempts' => (int) ($event['attempts'] ?? 0),
                    'replays' => (int) ($event['replays'] ?? 0),
                    'last_error' => $delivered ? ($event['last_error'] ?? null) : __('org_transfer.import.event_not_delivered'),
                    'received_at' => self::stamp($received),
                    'delivered_at' => self::stamp(self::time($event['delivered_at'] ?? null)),
                    'expires_at' => self::stamp($expires),
                    'created_at' => self::stamp($received),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);
                $inserted > 0 ? $imported++ : $skipped++;
            }
        });

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importUsage(ImportContext $context): int
    {
        $path = $context->file(self::usageEntry());
        if ($path === null || ! Schema::hasTable('imported_history')) {
            return 0;
        }
        $n = 0;
        $connection = (new ImportedHistoryRecord)->getConnection();
        $connection->transaction(function () use ($context, $path, $connection, &$n) {
            $batch = [];
            foreach (self::lines($path) as $record) {
                $project = isset($record['project_id']) ? $context->mapped('project', (string) $record['project_id']) : null;
                $batch[] = [
                    'id' => strtolower((string) Str::ulid()),
                    'org_transfer_id' => $context->transfer->id,
                    'kind' => ImportedHistoryRecord::KIND_USAGE,
                    'source_table' => mb_substr((string) ($record['table'] ?? ''), 0, 64),
                    'project_id' => $project,
                    'source_project_id' => $record['project_id'] ?? null,
                    'subject' => isset($record['subject']) ? mb_substr((string) $record['subject'], 0, 250) : null,
                    'period_date' => $record['date'] ?? null,
                    'data' => json_encode($record['data'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at' => now()->format('Y-m-d H:i:s'),
                ];
                if (count($batch) >= 200) {
                    $connection->table('imported_history')->insert($batch);
                    $n += count($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $connection->table('imported_history')->insert($batch);
                $n += count($batch);
            }
        });

        return $n;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * The records of a project's history file, one by one.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function records(ImportContext $context, string $sourceId): \Generator
    {
        $path = $context->file(self::projectEntry($sourceId));
        if ($path !== null) {
            yield from self::lines($path);
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    public static function lines(string $path): \Generator
    {
        $in = fopen($path, 'rb');
        if ($in === false) {
            return;
        }
        try {
            while (($line = fgets($in)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $record = json_decode($line, true);
                if (is_array($record)) {
                    yield $record;
                }
            }
        } finally {
            fclose($in);
        }
    }

    /** A run the target keeps: ended within its run retention (an active one ends at the import). */
    private function runKept(array $run, Carbon $cutoff): bool
    {
        if (! in_array($run['status'] ?? null, WorkflowRun::TERMINAL, true)) {
            return true;
        }
        $ended = self::time($run['ended_at'] ?? null);

        return $ended === null || $ended->gte($cutoff);
    }

    /** When an event leaves the inbox: its arrival plus the trigger's retention days. */
    private static function eventExpiry(array $event, int $days): Carbon
    {
        $max = max(1, (int) config('triggers.event_retention_max_days', 30));
        $days = max(1, min($days > 0 ? $days : (int) config('triggers.event_retention_days', 7), $max));

        return (self::time($event['received_at'] ?? null) ?? now())->copy()->addDays($days);
    }

    /** @param resource $out */
    private static function line($out, array $record): void
    {
        fwrite($out, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n");
    }

    /** A JSON column as the value it holds (raw, whatever the model's cast does with it). */
    private static function json(Model $model, string $column): mixed
    {
        $raw = $model->getRawOriginal($column);

        return is_string($raw) ? json_decode($raw, true) : $raw;
    }

    private static function encode(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function stamp(?Carbon $time): ?string
    {
        return $time?->copy()->utc()->format('Y-m-d H:i:s');
    }

    /** An id that sorts by its time, like the rows RunR made then (latestDeployment takes the highest). */
    private static function ulidAt(Carbon $time): string
    {
        return strtolower((string) Str::ulid($time));
    }
}
