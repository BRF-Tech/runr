<?php

namespace App\Services\Metering;

use App\Models\AiModelService;
use App\Models\AiModelServiceUsage;
use App\Models\BindingUsageRecord;
use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\UsageRecord;
use App\Services\Ai\Gpu\GpuRequest;
use App\Services\Helpers\WorkloadSizing;
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
 *  - every other deployment: its sessions that started on the day and ended (CPU millicore·ms,
 *    memory MiB·ms, duration, cold starts; GPU-unit ms = session pod time × the deployment's GPUs)
 *  - model services (source "model"): the metered pod time of models:meter, with the creator of
 *    the service (created_by: usage of no project is billed to its creator)
 *
 * One record per project, deployment, region, scale mode, trigger type and day: every session and
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
     * Projects with something to meter on the day: sessions that started on it, deployments that
     * ran on it, or records of it (recalculated, possibly to nothing). Existing projects only — the
     * records of a deleted project stay as they were.
     *
     * @return list<string>
     */
    private function projectIdsOf(Carbon $day): array
    {
        $date = $day->toDateString();

        $ids = DeploymentSession::query()->whereDate('started_at', $date)->whereNotNull('ended_at')->distinct()->pluck('project_id')
            ->merge($this->deploymentsOn($day)->distinct()->pluck('project_id'))
            ->merge(UsageRecord::query()->whereDate('record_date', $date)->where(fn ($q) => $q->whereNull('source')->orWhere('source', 'project'))->distinct()->pluck('project_id'));

        return $ids->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    /**
     * Deployments that may have run on the day: created before its end, and live, or ended after
     * its start (deployments of earlier releases without ended_at: changed after its start).
     *
     * @return Builder<Deployment>
     */
    private function deploymentsOn(Carbon $day, ?string $projectId = null): Builder
    {
        $start = $day->copy();
        $end = $day->copy()->addDay();
        $tracked = SchemaColumns::has(new Deployment, 'ended_at');

        return Deployment::query()
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

        $sessions = DeploymentSession::query()
            ->where('project_id', $project->id)
            ->whereDate('started_at', $date)
            ->whereNotNull('ended_at')
            ->get();

        $deployments = $this->deploymentsOn($day, (string) $project->id)->get()->keyBy(fn (Deployment $d) => (string) $d->id);
        $missing = $sessions->pluck('deployment_id')->map(fn ($id) => (string) $id)->unique()
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
            if ($alwaysOn) {
                $this->addReservation($rows, $project, $deployment, $sizing, $day, $until, $replacedAt[$id] ?? null);
            } else {
                $this->addSessions($rows, $project, $deployment, $sizing, $sessions->where('deployment_id', $deployment->id));
            }

            $region = (string) ($sizing['region_id'] ?? $project->knative_region_id);
            $ran[$region] = ($ran[$region] ?? 0) + $this->activeMs($deployment, $day, $until, $replacedAt[$id] ?? null);
        }

        $written = $this->replaceRecords($project, $date, $rows, $deployments->keys()->all(), $existing);
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
        $fullDays = $existing->filter(fn (UsageRecord $r) => $r->scale_mode === 'custom_scale'
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

        $own = $existing->filter(fn (UsageRecord $r) => (string) $r->deployment_id === (string) $deployment->id);
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
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', 'project'))
            ->get();
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
        ];

        $rows[implode('|', $keys)] = ['keys' => $keys, 'values' => [
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
        ]];
    }

    /**
     * A deployment that scales with its traffic: its sessions of the day, per region and trigger.
     *
     * @param  array<string, array{keys: array<string, mixed>, values: array<string, mixed>}>  $rows
     * @param  array{region_id: string|null, cpu_m: int, memory_mb: int, trigger_type: string, min_scale: int, max_scale: int, scale_to_zero: bool, gpu_count: int}  $sizing
     * @param  Collection<int, DeploymentSession>  $sessions
     */
    private function addSessions(array &$rows, Project $project, Deployment $deployment, array $sizing, Collection $sessions): void
    {
        $gpuCount = $sizing['gpu_count'];

        foreach ($sessions as $session) {
            $regionId = $session->region_id ?: ($sizing['region_id'] ?? $project->knative_region_id);
            if (! $regionId) {
                continue;
            }

            $keys = [
                'project_id' => (string) $project->id,
                'deployment_id' => (string) $deployment->id,
                'region_id' => (string) $regionId,
                'scale_mode' => 'scale_to_zero',
                'entry_point' => self::entryPoint($session->entry_point),
            ];
            $key = implode('|', $keys);
            $values = $rows[$key]['values'] ?? [
                'session_count' => 0, 'total_duration_ms' => 0, 'total_cpu_mc_ms' => 0, 'total_memory_mib_ms' => 0,
                'cold_start_count' => 0, 'reserved_cpu_m' => 0, 'reserved_memory_mb' => 0, 'min_scale' => 0,
                'gpu_count' => $gpuCount, 'total_gpu_unit_ms' => 0,
            ];

            $values['session_count']++;
            $values['total_duration_ms'] += (int) $session->duration_ms;
            $values['total_cpu_mc_ms'] += (int) $session->cpu_used_ms;
            $values['total_memory_mib_ms'] += (int) $session->memory_used_mb_ms;
            $values['cold_start_count'] += $session->cold_start ? 1 : 0;
            // GPU projects: every session's pods hold the deployment's GPUs for the session
            $values['total_gpu_unit_ms'] += (int) $session->duration_ms * max(1, (int) ($session->pod_count ?? 1)) * $gpuCount;

            $rows[$key] = ['keys' => $keys, 'values' => $values];
        }
    }

    /**
     * Milliseconds a deployment was active within [$day, $until): from ready_at (a deployment
     * marked active without one: its creation) until ended_at (earlier releases: its last change),
     * open while it is still live — and never past $replacedAt, when a newer deployment of the
     * project in its region started. A deployment that never became ready ran nothing.
     */
    private function activeMs(Deployment $deployment, Carbon $day, Carbon $until, ?Carbon $replacedAt = null): int
    {
        $start = self::startOf($deployment);
        if ($start === null) {
            return 0;
        }

        $end = $deployment->isLive() ? null : ($deployment->ended_at ?? $deployment->updated_at);
        if ($replacedAt !== null && ($end === null || $replacedAt->lt($end))) {
            $end = $replacedAt;
        }

        $from = $start->gt($day) ? $start : $day;
        $to = ($end !== null && $end->lt($until)) ? $end : $until;

        return $to->gt($from) ? (int) $from->diffInMilliseconds($to) : 0;
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
        $started = Deployment::query()
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
                if ((string) $newer->id === (string) $id || ! $later || $regionOf($newer) !== $region) {
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

        $byKey = $existing->groupBy(fn (UsageRecord $r) => implode('|', [$r->project_id, $r->deployment_id, $r->region_id, $r->scale_mode, $r->entry_point]));

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
