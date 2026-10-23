<?php

namespace App\Services\Metering;

use App\Models\AiModelService;
use App\Models\AiModelServiceUsage;
use App\Models\BindingUsageRecord;
use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectRegion;
use App\Models\UsageRecord;
use App\Services\Ai\Gpu\GpuRequest;
use App\Services\Contracts\KnativeConstants;
use App\Services\GlobalProjects\GlobalProjectRegions;
use App\Services\Helpers\WorkloadSizing;
use App\Services\Projects\WarmScaleService;
use App\Support\ConvertedOrganization;
use App\Support\PlatformConnection;
use App\Support\SchemaColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metering of the current organization context (docs/monitoring.md#metering): the day's usage of
 * every project and model service in usage_records — always on, with or without billing. No money
 * is calculated here; the Billing module prices the quantities when it calculates bills
 * (docs/billing.md), with the prices in effect on the record's day.
 *
 * A project's day is the sum over its deployments, each counted from what it really ran:
 *
 *  - always-on deployments (ResourceReservation::isAlwaysOn: a Knative Service with scale to zero
 *    off or min_scale >= 1): the size they were applied with (deployments.sizing, WorkloadSizing;
 *    applied before 0.5.8: the scale mode and size of their record of the day, sizingOn())
 *    × max(1, min_scale) for the time they were active on the day — from ready_at until ended_at,
 *    today until now, never past the start of a newer deployment of the project in the same
 *    region — as reserved_cpu_m / reserved_memory_mb / min_scale and as millicore·ms /
 *    MiB·ms / GPU-unit·ms; their sessions are covered by the reservation
 *  - every other deployment (scale to zero, a CronJob's runs): its sessions that ran on the day,
 *    each at the size the deployment was applied with for the session's pod time on the day
 *    (millicore·ms, MiB·ms and GPU-unit·ms = size × time × pods; a session over midnight on each
 *    day for its part, a running one until now; addSessions, SessionUsage), with their duration,
 *    sessions and cold starts (on the day they started), under the session's trigger type
 *  - a queue-triggered deployment's consumer (source "queue_consumer"): its reserved size for the
 *    time the deployment was active on the day (addQueueConsumer)
 *  - its preview environments (source "preview", one record per preview): their sessions, booked
 *    like the project's own at the size the preview ran with (addPreviewSessions)
 *  - deployments the timed warmer ran (deployments.sizing.warm_log, docs/scaling.md): the
 *    containers it kept warm in each stretch of their active time, as it applied them
 *    (WarmScaleService::appliedSegments), as a reservation; the sessions that started while it
 *    kept none as sessions
 *  - model services (source "model"): the metered pod time of models:meter, with the creator of
 *    the service (created_by: usage of no project is billed to its creator)
 *
 * Environments (docs/environments.md): a project's day covers the deployments of all its
 * environments; each record carries the environment of its deployment (environment_id, null: the
 * project's own workload) and its name (environment_name), so bills can show it — also once the
 * environment is deleted. A deployment is replaced only by a newer one of
 * the same environment.
 *
 * One record per project, deployment (or preview), region, scale mode, trigger type, source and
 * day: every session and
 * every second of a reservation is counted once, however often the project is redeployed. A day is
 * recalculated from scratch on every run and the records of the project's existing deployments are
 * replaced (a re-run gives the same totals; a deployment that did not run on the day has no record
 * of it — the first-hour run for yesterday never creates usage from today's state). Records of a
 * deployment that was deleted since stay as they were metered; a 0.5.7 always-on record of one keeps
 * only the part of the day no recalculated deployment of its region ran (trimDeletedFullDays).
 *
 * mirror(): inside a tenant, the usage records and binding usage records of platform regions are
 * copied into the central tables with the tenant's id — what the platform bills the tenant for
 * stays readable centrally; copies of records the tenant no longer has are removed.
 */
class UsageAggregator
{
    /** @var array<string, bool> context (tenant id or "central") => usage_records.created_by exists */
    private array $createdByColumn = [];

    /** Re-aggregate a day (Y-m-d) of the current context. Returns the records written. */
    public function aggregate(string $date): int
    {
        $day = Carbon::parse($date)->startOfDay();
        $written = 0;
        $converted = $this->convertedOrganization();

        foreach (array_chunk($this->projectIdsOf($day), 500) as $ids) {
            $projects = Project::query()->whereIn('id', $ids)->with(['xaasConfig', 'faasConfig', 'containerConfig'])->get();
            foreach ($projects as $project) {
                // Central context of a converted installation: metered in the tenant only
                if (! $converted->contains((string) $project->id)) {
                    $written += $this->aggregateProject($project, $day);
                }
            }
        }

        return $written + $this->aggregateModelServices($day->toDateString(), $converted);
    }

    /**
     * Central context of a converted installation: the projects and model services the conversion
     * copied into the tenant are metered there only (ConvertedOrganization).
     */
    private function convertedOrganization(): ConvertedOrganization
    {
        return new ConvertedOrganization;
    }

    /**
     * Inside a tenant: copy the usage in platform regions of the days $from … $to (Y-m-d, $to
     * defaults to $from) into the central tables (tenant_id = the tenant), upserted by id — every
     * run copies the running month again, so the central copy follows late corrections and the
     * first bill after an upgrade covers the whole month. Central copies of usage records the
     * tenant no longer has (a recalculated day) are removed. No-op in the central context and
     * without tenancy.
     *
     * @return int rows copied
     */
    public function mirror(string $from, ?string $to = null): int
    {
        $to ??= $from;
        $central = PlatformConnection::name();
        $tenantId = self::tenantKey();
        if ($central === null || $tenantId === null) {
            return 0;
        }

        $platformRegions = KnativeRegion::query()->where('is_synced', true)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if ($platformRegions === []) {
            return 0;
        }

        $copied = 0;
        $sources = [
            'usage_records' => UsageRecord::query(),
            'binding_usage_records' => Schema::hasTable('binding_usage_records') ? BindingUsageRecord::query() : null,
        ];

        foreach ($sources as $table => $query) {
            if ($query === null) {
                continue;
            }

            $query->whereDate('record_date', '>=', $from)
                ->whereDate('record_date', '<=', $to)
                ->whereIn('region_id', $platformRegions)
                ->chunkById(500, function ($rows) use ($central, $table, $tenantId, &$copied) {
                    $copy = [];
                    foreach ($rows as $row) {
                        $attributes = $row->getAttributes();
                        $attributes['tenant_id'] = $tenantId;
                        $attributes['record_date'] = Carbon::parse((string) $row->getRawOriginal('record_date'))->toDateString();
                        $copy[] = $attributes;
                    }
                    if ($copy === []) {
                        return;
                    }

                    $columns = array_values(array_diff(array_keys($copy[0]), ['id']));
                    DB::connection($central)->table($table)->upsert($copy, ['id'], $columns);
                    $copied += count($copy);
                });
        }

        $this->dropVanishedCopies($central, $tenantId, $platformRegions, $from, $to);

        return $copied;
    }

    /**
     * Central copies of usage records the tenant no longer has (a recalculated day replaces the
     * records of a project's deployments) are removed, so the platform does not bill them.
     *
     * @param  list<string>  $platformRegions
     */
    private function dropVanishedCopies(string $central, string $tenantId, array $platformRegions, string $from, string $to): void
    {
        $own = UsageRecord::query()
            ->whereDate('record_date', '>=', $from)->whereDate('record_date', '<=', $to)
            ->whereIn('region_id', $platformRegions)
            ->pluck('id')->map(fn ($id) => (string) $id)->flip();

        $vanished = UsageRecord::on($central)->where('tenant_id', $tenantId)
            ->whereDate('record_date', '>=', $from)->whereDate('record_date', '<=', $to)
            ->whereIn('region_id', $platformRegions)
            ->pluck('id')->map(fn ($id) => (string) $id)
            ->reject(fn (string $id) => $own->has($id))
            ->values()->all();

        foreach (array_chunk($vanished, 500) as $ids) {
            UsageRecord::on($central)->where('tenant_id', $tenantId)->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Projects with something to meter on the day: sessions that ran on it, deployments that ran
     * on it, or records of it (recalculated, possibly to nothing). Existing projects only — the
     * records of a deleted project stay as they were.
     *
     * @return list<string>
     */
    private function projectIdsOf(Carbon $day): array
    {
        $date = $day->toDateString();

        $ids = $this->sessionsOn($day)->distinct()->pluck('project_id')
            ->merge($this->deploymentsOn($day)->distinct()->pluck('project_id'))
            ->merge(UsageRecord::query()->whereDate('record_date', $date)->where(fn ($q) => self::projectSources($q))->distinct()->pluck('project_id'));

        return $ids->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    /**
     * Sessions that ran on the day: started before its end, and still running or ended after its
     * start (ended_at is stored to the second, so one that ended in the day's first second is in).
     *
     * @return Builder<DeploymentSession>
     */
    private function sessionsOn(Carbon $day, ?string $projectId = null): Builder
    {
        return DeploymentSession::query()
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->where('started_at', '<', $day->copy()->addDay())
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $day));
    }

    /**
     * Deployments that may have run on the day: created before its end, and live, or ended after
     * its start (deployments of earlier releases without ended_at: changed after its start). Not
     * the history an organization import brought (imported_at): it ran in another organization.
     *
     * @return Builder<Deployment>
     */
    private function deploymentsOn(Carbon $day, ?string $projectId = null): Builder
    {
        $start = $day->copy();
        $end = $day->copy()->addDay();
        $tracked = SchemaColumns::has(new Deployment, 'ended_at');

        // A deployment an organization import brought along ran elsewhere: never metered here
        return Deployment::ranHere(Deployment::query())
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->where('created_at', '<', $end)
            ->where(function ($q) use ($start, $tracked) {
                $q->whereIn('status', Deployment::LIVE);
                if ($tracked) {
                    $q->orWhere('ended_at', '>', $start)
                        ->orWhere(fn ($w) => $w->whereNull('ended_at')->where('updated_at', '>', $start));
                } else {
                    $q->orWhere('updated_at', '>', $start);
                }
            });
    }

    private function aggregateProject(Project $project, Carbon $day): int
    {
        $date = $day->toDateString();
        $dayEnd = $day->copy()->addDay();
        $until = now()->lt($dayEnd) ? now() : $dayEnd;

        $sessions = $this->sessionsOn($day, (string) $project->id)->get();

        $deployments = $this->deploymentsOn($day, (string) $project->id)->get()->keyBy(fn (Deployment $d) => (string) $d->id);
        $missing = $sessions->pluck('deployment_id')->filter()->map(fn ($id) => (string) $id)->unique()
            ->reject(fn (string $id) => $deployments->has($id))->values();
        if ($missing->isNotEmpty()) {
            foreach (Deployment::query()->whereIn('id', $missing->all())->get() as $deployment) {
                $deployments->put((string) $deployment->id, $deployment);
            }
        }

        $replacedAt = $this->replacedAt($project, $deployments, $dayEnd);
        $existing = $this->recordsOf($project, $date);

        /** @var array<string, array{keys: array<string, mixed>, values: array<string, mixed>}> $rows */
        $rows = [];
        /** @var array<string, int> $ran milliseconds the calculated deployments ran on the day, per region */
        $ran = [];
        foreach ($deployments as $id => $deployment) {
            [$sizing, $alwaysOn] = $this->sizingOn($deployment, $project, $day, $existing);
            $warmLog = WarmScaleService::logOf($deployment);
            if ($warmLog !== null) {
                $this->addWarmed($rows, $project, $deployment, $sizing, $warmLog, $day, $until, $replacedAt[$id] ?? null, $sessions->where('deployment_id', $deployment->id));
            } elseif ($alwaysOn) {
                $this->addReservation($rows, $project, $deployment, $sizing, $day, $until, $replacedAt[$id] ?? null);
                // a global project reserves the same size in every region whose copy follows the
                // deployment (docs/global-projects.md): one record per region
                foreach ($this->replicaRegions($project, $deployment) as $replicaRegion) {
                    $this->addReservation($rows, $project, $deployment, ['region_id' => $replicaRegion] + $sizing, $day, $until, $replacedAt[$id] ?? null);
                }
            } else {
                $this->addSessions($rows, $project, $deployment, $sizing, $sessions->where('deployment_id', $deployment->id), $day, $until);
            }
            if ($sizing['trigger_type'] === 'queue') {
                $this->addQueueConsumer($rows, $project, $deployment, $sizing, $day, $until, $replacedAt[$id] ?? null);
            }

            $region = (string) ($sizing['region_id'] ?? $project->knative_region_id);
            $ran[$region] = ($ran[$region] ?? 0) + $this->activeMs($deployment, $day, $until, $replacedAt[$id] ?? null);
        }

        // Preview environments: their own records, one per preview (all of the day calculated again)
        $previews = $this->addPreviewSessions($rows, $project, $sessions->filter(fn (DeploymentSession $s) => $s->preview_environment_id !== null), $day, $until);
        $previews = array_merge($previews, $existing->filter(fn (UsageRecord $r) => UsageRecord::sourceOf($r) === UsageRecord::SOURCE_PREVIEW)
            ->map(fn (UsageRecord $r) => (string) $r->deployment_id)->all());

        $written = $this->replaceRecords($project, $date, $rows, array_values(array_unique([...$deployments->keys()->all(), ...$previews])), $existing);
        $this->trimDeletedFullDays($existing, $deployments->keys()->all(), $ran, (int) $day->diffInMilliseconds($until));

        return $written;
    }

    /**
     * A deployment deleted since keeps its records as they were metered — except a 0.5.7 always-on
     * record (the reservation only, which bills count as 24 hours: UsageReader) on a day other
     * deployments of the project ran in its region: the day's recalculated records cover their time,
     * and the 24 hours would count it again (a redeploy's old deployment, deleted afterwards). It
     * keeps the rest of the day, the time none of them ran, at its recorded size; nothing left: it
     * goes. The rewritten record has its time in it and stays as it is on the next runs.
     *
     * @param  Collection<int, UsageRecord>  $existing  the project's records of the day before this run
     * @param  list<string>  $calculated  the deployments this run calculated
     * @param  array<string, int>  $ran  milliseconds they ran on the day, per region
     */
    private function trimDeletedFullDays(Collection $existing, array $calculated, array $ran, int $dayMs): void
    {
        $fullDays = $existing->filter(fn (UsageRecord $r) => $r->scale_mode === 'custom_scale' && UsageRecord::sourceOf($r) === UsageRecord::SOURCE_PROJECT
            && (int) $r->total_duration_ms <= 0 && (int) $r->total_cpu_mc_ms <= 0 && (int) $r->total_memory_mib_ms <= 0
            && ! in_array((string) $r->deployment_id, $calculated, true));
        if ($fullDays->isEmpty()) {
            return;
        }

        $exists = Deployment::query()->whereIn('id', $fullDays->pluck('deployment_id')->unique()->values()->all())
            ->pluck('id')->map(fn ($id) => (string) $id)->flip();

        foreach ($fullDays as $record) {
            $region = (string) $record->region_id;
            if ($exists->has((string) $record->deployment_id) || ($ran[$region] ?? 0) <= 0 || ! $record->exists) {
                continue;
            }

            $ms = max(0, $dayMs - $ran[$region]);
            if ($ms === 0) {
                $record->delete();

                continue;
            }

            $pods = max(1, (int) $record->min_scale);
            $record->fill([
                'min_scale' => $pods,
                'total_duration_ms' => $pods * $ms,
                'total_cpu_mc_ms' => $pods * (int) $record->reserved_cpu_m * $ms,
                'total_memory_mib_ms' => $pods * (int) $record->reserved_memory_mb * $ms,
                'total_gpu_unit_ms' => $pods * (int) $record->gpu_count * $ms,
            ])->save();
            $ran[$region] += $ms;
        }
    }

    /**
     * The size a deployment counts with on the day, and whether it was always on.
     *
     *  - recorded when it was applied (deployments.sizing): that size;
     *  - applied before 0.5.8 (nothing recorded): the scale mode, reserved size, min_scale, GPUs,
     *    trigger and region of its own record of the day — what 0.5.7 (metering every 5 minutes)
     *    or the first run of the day recorded, not today's config. Without one, on the days the
     *    metering job calculates (today and yesterday: the days after the upgrade) its project's
     *    config with its own overrides; on an earlier day no reservation is invented for it — a
     *    day 0.5.7 metered without a record of it saw it run for less than one 5-minute run, and a
     *    project 0.5.7 did not meter (outside a tenant) has no history to make up — only its
     *    sessions count.
     *
     * @param  Collection<int, UsageRecord>  $existing  the project's records of the day
     * @return array{0: array{region_id: string|null, cpu_m: int, memory_mb: int, trigger_type: string, min_scale: int, max_scale: int, scale_to_zero: bool, gpu_count: int}, 1: bool}
     */
    private function sizingOn(Deployment $deployment, Project $project, Carbon $day, Collection $existing): array
    {
        $sizing = WorkloadSizing::ofDeployment($deployment, $project);
        $recorded = is_array($deployment->sizing) && isset($deployment->sizing['cpu_m'], $deployment->sizing['memory_mb']);
        if ($recorded) {
            return [$sizing, WorkloadSizing::isAlwaysOn($sizing)];
        }

        // Its own workload's records (not its queue consumer's)
        $own = $existing->filter(fn (UsageRecord $r) => (string) $r->deployment_id === (string) $deployment->id && UsageRecord::sourceOf($r) === UsageRecord::SOURCE_PROJECT);
        /** @var UsageRecord|null $record */
        $record = $own->firstWhere('scale_mode', 'custom_scale') ?? $own->first();
        if ($record !== null) {
            $reserved = $record->scale_mode === 'custom_scale';

            return [[
                'region_id' => (string) $record->region_id !== '' ? (string) $record->region_id : $sizing['region_id'],
                'cpu_m' => $reserved ? (int) $record->reserved_cpu_m : $sizing['cpu_m'],
                'memory_mb' => $reserved ? (int) $record->reserved_memory_mb : $sizing['memory_mb'],
                'trigger_type' => $record->entry_point ?: $sizing['trigger_type'],
                'min_scale' => $reserved ? max(1, (int) $record->min_scale) : 0,
                'max_scale' => $sizing['max_scale'],
                'scale_to_zero' => ! $reserved,
                'gpu_count' => (int) $record->gpu_count,
            ], $reserved];
        }

        $jobDay = $day->gte(now()->subDay()->startOfDay());

        return [$sizing, $jobDay && WorkloadSizing::isAlwaysOn($sizing)];
    }

    /**
     * The project's records of the day (not model services).
     *
     * @return Collection<int, UsageRecord>
     */
    private function recordsOf(Project $project, string $date): Collection
    {
        return UsageRecord::query()
            ->where('project_id', $project->id)
            ->whereDate('record_date', $date)
            ->where(fn ($q) => self::projectSources($q))
            ->get();
    }

    /**
     * The other regions of a global project whose copy follows $deployment (they run its version,
     * built in their own registry, with its size). Sessions need no such list: the region agent of
     * each region reports its own (DeploymentSession::region_id).
     *
     * @return list<string>
     */
    private function replicaRegions(Project $project, Deployment $deployment): array
    {
        if (! $project->isGlobal() || ! GlobalProjectRegions::tableReady()) {
            return [];
        }

        return ProjectRegion::query()
            ->where('project_id', $project->getKey())
            ->where('deployment_id', $deployment->getKey())
            ->where('knative_region_id', '!=', (string) $project->knative_region_id)
            ->whereNotNull('served_version_id')
            ->pluck('knative_region_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * An always-on deployment: its reservation for the time it was active on the day.
     *
     * @param  array<string, array{keys: array<string, mixed>, values: array<string, mixed>}>  $rows
     * @param  array{region_id: string|null, cpu_m: int, memory_mb: int, trigger_type: string, min_scale: int, max_scale: int, scale_to_zero: bool, gpu_count: int}  $sizing
     */
    private function addReservation(array &$rows, Project $project, Deployment $deployment, array $sizing, Carbon $day, Carbon $until, ?Carbon $replacedAt): void
    {
        $regionId = $sizing['region_id'] ?? $project->knative_region_id;
        $ms = $this->activeMs($deployment, $day, $until, $replacedAt);
        if (! $regionId || $ms <= 0) {
            return;
        }

        $pods = WorkloadSizing::reservedPods($sizing);
        $keys = [
            'project_id' => (string) $project->id,
            'deployment_id' => (string) $deployment->id,
            'region_id' => (string) $regionId,
            'scale_mode' => 'custom_scale',
            'entry_point' => self::entryPoint($sizing['trigger_type']),
            'source' => UsageRecord::SOURCE_PROJECT,
        ];

        $rows[implode('|', $keys)] = ['keys' => $keys, 'values' => $this->withEnvironment($deployment, [
            'session_count' => 0,
            'cold_start_count' => 0,
            'reserved_cpu_m' => $sizing['cpu_m'],
            'reserved_memory_mb' => $sizing['memory_mb'],
            'min_scale' => $pods,
            // The reservation of the active time, in the units of the session records
            'total_duration_ms' => $pods * $ms,
            'total_cpu_mc_ms' => $pods * $sizing['cpu_m'] * $ms,
            'total_memory_mib_ms' => $pods * $sizing['memory_mb'] * $ms,
            'gpu_count' => $sizing['gpu_count'],
            'total_gpu_unit_ms' => $pods * $sizing['gpu_count'] * $ms,
        ])];
    }

    /**
     * A deployment that scales with its traffic (or a CronJob's runs): its sessions that ran on the
     * day, per region and trigger. A session is the time one pod held the deployment's size, so it
     * counts that size — CPU millicores, memory MiB and GPUs, as an always-on deployment reserves
     * them — for its pod time on the day (SessionUsage): the part of [start, end) within the day,
     * a running session until $until (now, or the day's end). A session over midnight or a month's
     * end counts on each day for the time it ran there; it counts as a session (and a cold start)
     * on the day it started. Figures the session row may carry (cpu_used_ms, memory_used_mb_ms)
     * are not used: no agent reports them, and every session, of any agent version and also one
     * closeStale() ended, is metered the same way.
     *
     * @param  array<string, array{keys: array<string, mixed>, values: array<string, mixed>}>  $rows
     * @param  array{region_id: string|null, cpu_m: int, memory_mb: int, trigger_type: string, min_scale: int, max_scale: int, scale_to_zero: bool, gpu_count: int}  $sizing
     * @param  Collection<int, DeploymentSession>  $sessions
     */
    private function addSessions(array &$rows, Project $project, Deployment $deployment, array $sizing, Collection $sessions, Carbon $day, Carbon $until): void
    {
        foreach ($sessions as $session) {
            $this->addSession($rows, $session, [
                'project_id' => (string) $project->id,
                'deployment_id' => (string) $deployment->id,
                'region_id' => (string) ($session->region_id ?: ($sizing['region_id'] ?? $project->knative_region_id)),
                'scale_mode' => 'scale_to_zero',
                'entry_point' => self::entryPoint($session->entry_point),
                'source' => UsageRecord::SOURCE_PROJECT,
            ], $sizing, $day, $until, $deployment);
        }
    }

    /**
     * A deployment the timed warmer ran (deployments.sizing.warm_log): the containers it kept warm
     * in each stretch of the deployment's active time on the day, as the warmer applied them to the
     * PodAutoscaler — one reservation record (custom_scale, min_scale = the most it kept) — and the
     * sessions that started while it kept none, as a scale-to-zero deployment's.
     *
     * @param  array<string, array{keys: array<string, mixed>, values: array<string, mixed>}>  $rows
     * @param  array{region_id: string|null, cpu_m: int, memory_mb: int, trigger_type: string, min_scale: int, max_scale: int, scale_to_zero: bool, gpu_count: int}  $sizing
     * @param  list<array{0: int, 1: int}>  $warmLog
     * @param  Collection<int, DeploymentSession>  $sessions
     */
    private function addWarmed(array &$rows, Project $project, Deployment $deployment, array $sizing, array $warmLog, Carbon $day, Carbon $until, ?Carbon $replacedAt, Collection $sessions): void
    {
        $window = $this->activeWindow($deployment, $day, $until, $replacedAt);

        $podMs = 0;
        $most = 0;
        foreach ($window ? WarmScaleService::appliedSegments($warmLog, $deployment, $window[0], $window[1]) : [] as [$start, $end, $count]) {
            $podMs += ($end - $start) * 1000 * $count;
            $most = max($most, $count);
        }

        $regionId = $sizing['region_id'] ?? $project->knative_region_id;
        if ($regionId && $podMs > 0) {
            $keys = [
                'project_id' => (string) $project->id,
                'deployment_id' => (string) $deployment->id,
                'region_id' => (string) $regionId,
                'scale_mode' => 'custom_scale',
                'entry_point' => self::entryPoint($sizing['trigger_type']),
                'source' => UsageRecord::SOURCE_PROJECT,
            ];
            $rows[implode('|', $keys)] = ['keys' => $keys, 'values' => $this->withEnvironment($deployment, [
                'session_count' => 0,
                'cold_start_count' => 0,
                'reserved_cpu_m' => $sizing['cpu_m'],
                'reserved_memory_mb' => $sizing['memory_mb'],
                'min_scale' => $most,
                // The warm containers of the active time, in the units of the session records
                'total_duration_ms' => $podMs,
                'total_cpu_mc_ms' => $podMs * $sizing['cpu_m'],
                'total_memory_mib_ms' => $podMs * $sizing['memory_mb'],
                'gpu_count' => $sizing['gpu_count'],
                'total_gpu_unit_ms' => $podMs * $sizing['gpu_count'],
            ])];
        }

        // Sessions are covered while containers were kept warm
        $cold = $sessions->filter(
            fn (DeploymentSession $session) => WarmScaleService::appliedAt($warmLog, $deployment, $session->started_at) < 1
        );
        $this->addSessions($rows, $project, $deployment, $sizing, $cold, $day, $until);
    }

    /**
     * A project's preview environments (PreviewManifests: scale to zero, at most 3 pods): their
     * sessions that ran on the day, booked like the project's own (addSessions) at the size the
     * preview ran with — the project's, kept with the session (metadata.size; without it, the
     * project's current size) — as records of their own (source preview, one per preview:
     * deployment_id carries the preview's id), billed on the project's bill as their own line.
     *
     * @param  array<string, array{keys: array<string, mixed>, values: array<string, mixed>}>  $rows
     * @param  Collection<int, DeploymentSession>  $sessions
     * @return list<string> the previews calculated
     */
    private function addPreviewSessions(array &$rows, Project $project, Collection $sessions, Carbon $day, Carbon $until): array
    {
        $fallback = null;
        $previews = [];

        foreach ($sessions as $session) {
            $size = $session->metadata['size'] ?? null;
            $sizing = is_array($size) && isset($size['cpu_m'], $size['memory_mb'])
                ? ['cpu_m' => (int) $size['cpu_m'], 'memory_mb' => (int) $size['memory_mb'], 'gpu_count' => (int) ($size['gpu_count'] ?? 0)]
                : ($fallback ??= WorkloadSizing::of($project, ['scale_to_zero' => true, 'min_scale' => 0]));

            $preview = (string) $session->preview_environment_id;
            $previews[] = $preview;
            $this->addSession($rows, $session, [
                'project_id' => (string) $project->id,
                'deployment_id' => $preview,
                'region_id' => (string) ($session->region_id ?: $project->knative_region_id),
                'scale_mode' => 'scale_to_zero',
                'entry_point' => 'http',
                'source' => UsageRecord::SOURCE_PREVIEW,
            ], $sizing, $day, $until);
        }

        return array_values(array_unique($previews));
    }

    /**
     * Book one session's part of the day into the record of $keys: its time in the day (a running
     * one until $until) × its pods at $sizing; a session and a cold start on the day it started.
     *
     * @param  array<string, array{keys: array<string, mixed>, values: array<string, mixed>}>  $rows
     * @param  array<string, string>  $keys
     * @param  array{cpu_m: int, memory_mb: int, gpu_count: int}  $sizing
     * @param  Deployment|null  $deployment  the deployment the session ran (its environment goes on the record); null: a preview
     */
    private function addSession(array &$rows, DeploymentSession $session, array $keys, array $sizing, Carbon $day, Carbon $until, ?Deployment $deployment = null): void
    {
        if ($keys['region_id'] === '') {
            return;
        }

        $dayEnd = $day->copy()->addDay();
        $ms = SessionUsage::msWithin($session, $day, $dayEnd, runningUntil: $until);
        $startedOnDay = $session->started_at->gte($day) && $session->started_at->lt($dayEnd);
        if ($ms <= 0 && ! $startedOnDay) {
            return;
        }

        $key = implode('|', $keys);
        $empty = [
            'session_count' => 0, 'total_duration_ms' => 0, 'total_cpu_mc_ms' => 0, 'total_memory_mib_ms' => 0,
            'cold_start_count' => 0, 'reserved_cpu_m' => 0, 'reserved_memory_mb' => 0, 'min_scale' => 0,
            'gpu_count' => $sizing['gpu_count'], 'total_gpu_unit_ms' => 0,
        ];
        $values = $rows[$key]['values'] ?? ($deployment !== null ? $this->withEnvironment($deployment, $empty) : $empty);

        $usage = SessionUsage::at($ms * SessionUsage::pods($session), $sizing);

        $values['session_count'] += $startedOnDay ? 1 : 0;
        $values['total_duration_ms'] += $ms;
        $values['total_cpu_mc_ms'] += $usage['cpu_mc_ms'];
        $values['total_memory_mib_ms'] += $usage['memory_mib_ms'];
        $values['cold_start_count'] += $startedOnDay && $session->cold_start ? 1 : 0;
        $values['total_gpu_unit_ms'] += $usage['gpu_unit_ms'];

        $rows[$key] = ['keys' => $keys, 'values' => $values];
    }

    /**
     * The consumer of a queue-triggered deployment (QueueConsumerManifests: one pod of
     * KnativeConstants::QUEUE_CONSUMER_CPU_M / QUEUE_CONSUMER_MEMORY_MB that runs as long as the
     * deployment, scale to zero or not): its reserved size for the time the deployment was active
     * on the day, as a record of its own (source queue_consumer). Metered always; the Billing
     * module bills it only where the region charges queue consumers (docs/billing.md#queue-consumers).
     *
     * @param  array<string, array{keys: array<string, mixed>, values: array<string, mixed>}>  $rows
     * @param  array{region_id: string|null, cpu_m: int, memory_mb: int, trigger_type: string, min_scale: int, max_scale: int, scale_to_zero: bool, gpu_count: int}  $sizing
     */
    private function addQueueConsumer(array &$rows, Project $project, Deployment $deployment, array $sizing, Carbon $day, Carbon $until, ?Carbon $replacedAt): void
    {
        $regionId = $sizing['region_id'] ?? $project->knative_region_id;
        $ms = $this->activeMs($deployment, $day, $until, $replacedAt);
        if (! $regionId || $ms <= 0) {
            return;
        }

        $keys = [
            'project_id' => (string) $project->id,
            'deployment_id' => (string) $deployment->id,
            'region_id' => (string) $regionId,
            'scale_mode' => 'custom_scale',
            'entry_point' => 'queue',
            'source' => UsageRecord::SOURCE_QUEUE_CONSUMER,
        ];
        $cpuM = KnativeConstants::QUEUE_CONSUMER_CPU_M;
        $memoryMb = KnativeConstants::QUEUE_CONSUMER_MEMORY_MB;

        // the consumer of an environment's deployment is that environment's
        $rows[implode('|', $keys)] = ['keys' => $keys, 'values' => $this->withEnvironment($deployment, [
            'session_count' => 0,
            'cold_start_count' => 0,
            'reserved_cpu_m' => $cpuM,
            'reserved_memory_mb' => $memoryMb,
            'min_scale' => 1,
            'total_duration_ms' => $ms,
            'total_cpu_mc_ms' => $cpuM * $ms,
            'total_memory_mib_ms' => $memoryMb * $ms,
            'gpu_count' => 0,
            'total_gpu_unit_ms' => 0,
        ])];
    }

    /**
     * Milliseconds a deployment was active within [$day, $until): from ready_at (a deployment
     * marked active without one: its creation) until ended_at (earlier releases: its last change),
     * open while it is still live — and never past $replacedAt, when a newer deployment of the
     * project in its region started. A deployment that never became ready ran nothing.
     */
    private function activeMs(Deployment $deployment, Carbon $day, Carbon $until, ?Carbon $replacedAt = null): int
    {
        $window = $this->activeWindow($deployment, $day, $until, $replacedAt);

        return $window ? (int) $window[0]->diffInMilliseconds($window[1]) : 0;
    }

    /**
     * The stretch of [$day, $until) a deployment was active (see activeMs()), null when none.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function activeWindow(Deployment $deployment, Carbon $day, Carbon $until, ?Carbon $replacedAt = null): ?array
    {
        $start = self::startOf($deployment);
        if ($start === null) {
            return null;
        }

        $end = $deployment->isLive() ? null : ($deployment->ended_at ?? $deployment->updated_at);
        if ($replacedAt !== null && ($end === null || $replacedAt->lt($end))) {
            $end = $replacedAt;
        }

        $from = $start->gt($day) ? $start : $day;
        $to = ($end !== null && $end->lt($until)) ? $end : $until;

        return $to->gt($from) ? [$from, $to] : null;
    }

    /** When a deployment started running: ready_at, or the creation of one marked active without it. */
    private static function startOf(Deployment $deployment): ?Carbon
    {
        return $deployment->ready_at ?? ($deployment->status === 'active' ? $deployment->created_at : null);
    }

    /**
     * When each deployment was replaced at the latest: the start of the first deployment of the
     * project created after it in the same region. A deploy replaces every live deployment
     * (LiveDeployments), so two of a project never run side by side in a region; this keeps the
     * metering right where they did anyway (rows of earlier releases, two deploys at once, a
     * deployment left active): the older one is not counted past the newer one's start.
     *
     * @param  Collection<string, Deployment>  $deployments
     * @return array<string, Carbon> deployment id => replaced at
     */
    private function replacedAt(Project $project, Collection $deployments, Carbon $dayEnd): array
    {
        /** @var Carbon|null $oldest */
        $oldest = $deployments->map(fn (Deployment $d) => $d->created_at)->filter()->min();
        if ($oldest === null) {
            return [];
        }

        // The region a deployment was applied in (WorkloadSizing::ofDeployment), without its size
        $regionOf = function (Deployment $d) use ($project): ?string {
            $region = is_array($d->sizing) && isset($d->sizing['region_id']) ? $d->sizing['region_id'] : $project->knative_region_id;

            return $region !== null ? (string) $region : null;
        };
        $started = Deployment::ranHere(Deployment::query())
            ->where('project_id', $project->id)
            ->where('created_at', '>=', $oldest)
            ->where('created_at', '<', $dayEnd)
            ->get()
            ->filter(fn (Deployment $d) => self::startOf($d) !== null);

        $replaced = [];
        foreach ($deployments as $id => $deployment) {
            $region = $regionOf($deployment);
            foreach ($started as $newer) {
                $later = $newer->created_at?->gt($deployment->created_at)
                    || ($newer->created_at?->eq($deployment->created_at) && (string) $newer->id > (string) $deployment->id);
                // Another environment's deployment never replaces this one
                if ((string) $newer->id === (string) $id || ! $later || $regionOf($newer) !== $region
                    || (string) $newer->getAttribute('environment_id') !== (string) $deployment->getAttribute('environment_id')) {
                    continue;
                }
                $start = self::startOf($newer);
                if ($start !== null && (! isset($replaced[$id]) || $start->lt($replaced[$id]))) {
                    $replaced[$id] = $start;
                }
            }
        }

        return $replaced;
    }

    /**
     * Write the day's records of a project: computed rows replace the records of the deployments
     * they were computed from (one per key, older duplicates go); records of existing deployments
     * that computed to nothing are removed. Records of deployments that no longer exist are kept.
     *
     * @param  array<string, array{keys: array<string, mixed>, values: array<string, mixed>}>  $rows
     * @param  list<string>  $deploymentIds  the deployments this run calculated
     * @param  Collection<int, UsageRecord>  $existing  the project's records of the day
     */
    private function replaceRecords(Project $project, string $date, array $rows, array $deploymentIds, Collection $existing): int
    {
        $stillThere = $existing->isEmpty() ? collect() : Deployment::query()
            ->whereIn('id', $existing->pluck('deployment_id')->unique()->values()->all())
            ->pluck('id')->map(fn ($id) => (string) $id)->flip();
        $calculated = array_flip($deploymentIds);

        $byKey = $existing->groupBy(fn (UsageRecord $r) => implode('|', [$r->project_id, $r->deployment_id, $r->region_id, $r->scale_mode, $r->entry_point, UsageRecord::sourceOf($r)]));

        foreach ($rows as $key => $row) {
            /** @var Collection<int, UsageRecord> $matches */
            $matches = $byKey->pull($key) ?? collect();
            $record = $matches->shift() ?? new UsageRecord([...$row['keys'], 'record_date' => $date]);
            $matches->each(fn (UsageRecord $duplicate) => $duplicate->delete());

            // Quantities only — prices are applied when bills are calculated (docs/billing.md)
            $record->fill($row['values'] + ['raw_cost' => 0, 'final_cost' => 0, 'multiplier' => 1, 'currency' => self::currency()])->save();
        }

        // Left over: records of a deployment that computes to nothing now (or under another key)
        foreach ($byKey as $records) {
            foreach ($records as $record) {
                $deploymentId = (string) $record->deployment_id;
                if (isset($calculated[$deploymentId]) || $stillThere->has($deploymentId)) {
                    $record->delete();
                }
            }
        }

        return count($rows);
    }

    /**
     * One record per model service and day (source "model"; project_id / deployment_id carry the
     * service id): the metered replica, CPU, memory and GPU time — services with min_scale ≥ 1 and
     * scale-to-zero ones alike, the meter counts the pods that actually ran.
     */
    private function aggregateModelServices(string $date, ConvertedOrganization $converted): int
    {
        try {
            $usages = AiModelServiceUsage::query()->whereDate('record_date', $date)->get()
                ->reject(fn (AiModelServiceUsage $u) => $converted->contains((string) $u->ai_model_service_id));
        } catch (QueryException) {
            return 0; // schema without the AI tables (migrations pending)
        }

        $services = AiModelService::query()->whereIn('id', $usages->pluck('ai_model_service_id'))->get()->keyBy('id');

        foreach ($usages as $usage) {
            /** @var AiModelService|null $service */
            $service = $services->get($usage->ai_model_service_id);

            $this->upsert($date, [
                'project_id' => $usage->ai_model_service_id,
                'deployment_id' => $usage->ai_model_service_id,
                'region_id' => $usage->knative_region_id,
                'scale_mode' => $service && ! $service->scale_to_zero ? 'custom_scale' : 'scale_to_zero',
                'entry_point' => 'http',
            ], [
                'source' => 'model',
                'created_by' => $service?->created_by,
                'session_count' => 0,
                'total_duration_ms' => (int) $usage->replica_ms,
                'total_cpu_mc_ms' => (int) $usage->cpu_mc_ms,
                'total_memory_mib_ms' => (int) $usage->memory_mib_ms,
                'min_scale' => (int) ($service->min_scale ?? 0),
                'gpu_count' => $service ? (GpuRequest::forService($service)->count ?? 0) : 0,
                'total_gpu_unit_ms' => (int) $usage->gpu_unit_ms,
            ]);
        }

        return $usages->count();
    }

    /**
     * Updates the day's record of these keys, or creates it. whereDate: the date cast stores
     * "Y-m-d 00:00:00" where the column is text (SQLite), so an equality on the date string (as
     * updateOrCreate does) misses the row and every run would add another record of the day.
     *
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     */
    private function upsert(string $date, array $keys, array $values): void
    {
        $record = UsageRecord::query()->where($keys)->whereDate('record_date', $date)->first()
            ?? new UsageRecord([...$keys, 'record_date' => $date]);

        // Quantities only — prices are applied when bills are calculated (docs/billing.md)
        $values += ['raw_cost' => 0, 'final_cost' => 0, 'multiplier' => 1, 'currency' => self::currency()];

        // A tenant schema migrated before created_by existed (TenantMigrations/0059 pending)
        if (array_key_exists('created_by', $values) && ! $this->hasCreatedBy()) {
            unset($values['created_by']);
        }

        $record->fill($values)->save();
    }

    /** The install's billing currency (usage_records.currency is NOT NULL). */
    public static function currency(): string
    {
        $currency = strtoupper(trim((string) config('billing.currency', 'USD')));

        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }

    /** The current tenant's id inside a tenant context, null otherwise. */
    public static function tenantKey(): ?string
    {
        if (! config('tenancy.enabled') || ! function_exists('tenancy') || ! tenancy()->initialized) {
            return null;
        }

        $key = tenancy()->tenant?->getTenantKey();

        return $key === null ? null : (string) $key;
    }

    /**
     * Records of projects — their own workload, previews and queue consumers — not of model
     * services (rows of earlier releases may have no source).
     *
     * @param  Builder<UsageRecord>  $query
     */
    public static function projectSources(Builder $query): void
    {
        $query->whereNull('source')->orWhere('source', '!=', UsageRecord::SOURCE_MODEL);
    }

    /**
     * A record's values with the environment of its deployment (skipped on a schema without the
     * column: TenantMigrations/0080 pending).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withEnvironment(Deployment $deployment, array $values): array
    {
        if (! SchemaColumns::has(new UsageRecord, 'environment_id')) {
            return $values;
        }

        $environmentId = $deployment->getAttribute('environment_id');
        $values['environment_id'] = $environmentId;

        // Its name too, so a bill names the environment after it was deleted (UsageEnvironmentSchema);
        // a name that cannot be read is not written over the one the record has
        if (SchemaColumns::has(new UsageRecord, 'environment_name')) {
            $name = $environmentId !== null && $environmentId !== '' ? $this->environmentName((string) $environmentId) : null;
            if ($name !== null || $environmentId === null || $environmentId === '') {
                $values['environment_name'] = $name;
            }
        }

        return $values;
    }

    /** @var array<string, string|null> context|environment id => its name */
    private array $environmentNames = [];

    private function environmentName(string $environmentId): ?string
    {
        $key = (self::tenantKey() ?? 'central').'|'.$environmentId;
        if (! array_key_exists($key, $this->environmentNames)) {
            try {
                $this->environmentNames[$key] = ProjectEnvironment::tablesReady()
                    ? ProjectEnvironment::query()->whereKey($environmentId)->value('name')
                    : null;
            } catch (QueryException) {
                $this->environmentNames[$key] = null;
            }
        }

        return $this->environmentNames[$key];
    }

    /** usage_records.entry_point is http | schedule | queue: a `workflow` project runs a Knative Service like http. */
    private static function entryPoint(?string $trigger): string
    {
        return in_array($trigger, ['http', 'schedule', 'queue'], true) ? (string) $trigger : 'http';
    }

    private function hasCreatedBy(): bool
    {
        $context = self::tenantKey() ?? 'central';

        return $this->createdByColumn[$context] ??= Schema::hasColumn((new UsageRecord)->getTable(), 'created_by');
    }
}
