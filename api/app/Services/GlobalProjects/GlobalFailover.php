<?php

namespace App\Services\GlobalProjects;

use App\Jobs\Bindings\SyncProjectBindingsJob;
use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Jobs\GlobalProjects\MoveGlobalPrimaryJob;
use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Services\Audit\AuditLogger;
use App\Services\GlobalDns\GlobalDnsServerService;
use App\Services\GlobalDns\GlobalDnsZone;
use App\Services\GlobalProjects\Data\RegionalData;
use App\Services\KubernetesClient;
use App\Triggers\ProjectTriggers;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Moves of a global project's primary (docs/global-projects.md, "Failover"):
 *
 *  - switchover (PUT /projects/{project}/global with primary_region_id): both regions answer. A
 *    dedicated Postgres binding's primary is switched over first (nothing written is lost), then
 *    the project's primary, its triggers and the DNS answer move;
 *  - failover (automatic, or POST /projects/{project}/global/failover): the primary does not
 *    answer. The replica of the target is promoted at once (writes after its last received WAL
 *    are lost), the primary, the triggers and the DNS answer move, and the old primary is fenced:
 *    out of the DNS answers, its triggers stopped, its data rebuilt from the new primary once its
 *    cluster answers again (rejoin), then it serves as a secondary.
 *
 * check() runs every minute (GlobalProjectsMaintenanceJob): the health of every global project's
 * regions as PowerDNS checks them (/global-health/<slug> on the region's Traefik address; a
 * project that only runs a CronJob: the region's cluster API), the automatic failover of a primary
 * that failed for global_dns.failover.after_seconds, the rejoin of fenced regions.
 */
class GlobalFailover
{
    /** Seconds a failover waits for an old primary that still answers to hand its data over. */
    public const HANDOVER_SECONDS = 120;

    public function __construct(
        private readonly RegionalData $data,
        private readonly GlobalTriggers $triggers,
    ) {}

    /** A move of the project's primary is running (the region it goes to), null otherwise. */
    public static function moving(Project $project): ?string
    {
        $to = Cache::get(self::lockKey($project));

        return is_string($to) ? $to : null;
    }

    public static function lockKey(Project $project): string
    {
        return 'global_move:'.$project->getKey();
    }

    /**
     * Start a move of the primary to $to: at once when no binding has replica clusters, else in
     * the background (MoveGlobalPrimaryJob) — the promotion waits for CloudNativePG.
     */
    public function start(Project $project, KnativeRegion $to, string $mode, bool $automatic = false): bool
    {
        if (self::moving($project) !== null) {
            return false;
        }
        Cache::put(self::lockKey($project), (string) $to->getKey(), now()->addMinutes(30));

        if ($this->replicatedBindings($project) === []) {
            try {
                $mode === 'failover' ? $this->failover($project, $to, $automatic) : $this->switchover($project, $to);
            } finally {
                Cache::forget(self::lockKey($project));
            }

            return true;
        }

        MoveGlobalPrimaryJob::dispatch($project, (string) $to->getKey(), $mode, $automatic);

        return true;
    }

    /** Planned switchover of the primary to $to (its copy serves the active version). */
    public function switchover(Project $project, KnativeRegion $to): void
    {
        $from = $project->knativeRegion;
        $moved = [];
        foreach ($this->replicatedBindings($project) as $binding) {
            try {
                $this->data->switchover($binding, $to);
                $moved[] = $binding;
            } catch (Throwable $e) {
                Log::error('Global project switchover: a binding did not switch over', ['project' => $project->slug, 'binding' => $binding->name, 'error' => $e->getMessage()]);
                // the bindings already moved stay with $to; the project stays where it is
                $this->rowMessage($project, $to, __('api.global.switchover_failed', ['binding' => $binding->name, 'reason' => $e->getMessage()]));
                if ($from) {
                    foreach ($moved as $back) {
                        try {
                            $this->data->switchover($back, $from);
                        } catch (Throwable) {
                            // left for the operator: the status message names it
                        }
                    }
                }

                throw new RuntimeException(__('api.global.switchover_failed', ['binding' => $binding->name, 'reason' => $e->getMessage()]), 0, $e);
            }
        }

        $this->movePrimary($project, $to, fence: false);
    }

    /** Failover of the primary to $to: the primary does not answer. */
    public function failover(Project $project, KnativeRegion $to, bool $automatic = false): void
    {
        $from = $project->knativeRegion;
        $answers = $this->answers($from);
        foreach ($this->replicatedBindings($project) as $binding) {
            // the old primary's cluster still answers (its copy failed, not its database): a
            // switchover loses nothing; the promotion at once only when it does not hand over
            if ($answers) {
                try {
                    $this->data->switchover($binding, $to, self::HANDOVER_SECONDS);

                    continue;
                } catch (Throwable $e) {
                    Log::warning('Global project failover: the old primary did not hand over, promoting at once', ['project' => $project->slug, 'binding' => $binding->name, 'error' => $e->getMessage()]);
                }
            }
            $this->data->failover($binding->fresh(['region']) ?? $binding, $to);
        }

        $this->movePrimary($project, $to, fence: true);
        if ($automatic) {
            $this->audit('project.global.failover', $project, ['from' => $from?->name, 'to' => $to->name, 'automatic' => true]);
        }
    }

    /**
     * Validation of a failover (POST /projects/{project}/global/failover): field => message.
     *
     * @return array<string, string>
     */
    public function failoverErrors(Project $project, ?string $regionId): array
    {
        if (self::moving($project) !== null) {
            return ['region_id' => __('api.global.move_running')];
        }
        $rows = ProjectRegion::query()->where('project_id', $project->getKey())->get()->keyBy(fn (ProjectRegion $r) => (string) $r->knative_region_id);
        $target = $regionId !== null ? $rows->get($regionId) : $this->target($project, $rows->values()->all());
        if ($target === null) {
            return ['region_id' => __('api.global.failover_no_target')];
        }
        if ((string) $target->knative_region_id === (string) $project->knative_region_id) {
            return ['region_id' => __('api.global.failover_is_primary')];
        }
        /** @var Deployment|null $active */
        $active = $project->activeDeployment()->first();
        if ($target->status !== ProjectRegion::STATUS_READY || ($active && $target->served_version_id !== $active->version_id)) {
            return ['region_id' => __('api.global.primary_not_ready')];
        }

        return [];
    }

    /**
     * The region a failover goes to: the first one in HA order whose copy serves the active
     * version, answers its health check and reaches the project's data.
     *
     * @param  list<ProjectRegion>  $rows
     */
    public function target(Project $project, array $rows): ?ProjectRegion
    {
        usort($rows, fn (ProjectRegion $a, ProjectRegion $b) => $a->position <=> $b->position);
        foreach ($rows as $row) {
            if ((string) $row->knative_region_id === (string) $project->knative_region_id) {
                continue;
            }
            if ($row->status === ProjectRegion::STATUS_READY && $row->healthy !== false && $row->isAnswerable()) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Every minute: health of the regions, automatic failovers, rejoins of fenced regions, trigger
     * workloads of regions that did not answer when the primary moved.
     */
    public function check(): void
    {
        if (! GlobalProjectRegions::tableReady()) {
            return;
        }

        $after = max(30, (int) config('global_dns.failover.after_seconds', 180));
        foreach (Project::query()->where('placement', Project::PLACEMENT_GLOBAL)->with('knativeRegion')->get() as $project) {
            try {
                $this->checkProject($project, $after);
            } catch (Throwable $e) {
                Log::warning('Global project failover check failed', ['project' => $project->slug, 'error' => $e->getMessage()]);
            }
        }
    }

    private function checkProject(Project $project, int $after): void
    {
        if ($project->isStopped() || self::moving($project) !== null) {
            return;
        }

        $rows = ProjectRegion::query()->where('project_id', $project->getKey())->with('knativeRegion')->orderBy('position')->get();
        $cronOnly = app(ProjectTriggers::class)->plan($project)->cronJob();

        // every region's check at once (a region that does not answer costs its timeout only once)
        $health = $this->healthOf($project, $rows->all(), $cronOnly);
        foreach ($rows as $row) {
            if ($row->status === ProjectRegion::STATUS_REMOVING || ! $row->knativeRegion) {
                continue;
            }
            $healthy = $health[(string) $row->getKey()] ?? false;
            $row->forceFill([
                'healthy' => $healthy,
                'health_checked_at' => now(),
                'unhealthy_since' => $healthy ? null : ($row->unhealthy_since ?? now()),
            ])->save();

            if ($row->status === ProjectRegion::STATUS_FENCED && $this->answers($row->knativeRegion)) {
                $this->rejoin($project, $row);
            } elseif ($row->status !== ProjectRegion::STATUS_FENCED && $row->getAttribute('triggers_active') && (string) $row->knative_region_id !== (string) $project->knative_region_id) {
                // the primary moved while this region did not answer: its triggers stop now
                $this->triggers->place($project);
            }
        }

        $primary = $rows->first(fn (ProjectRegion $r) => (string) $r->knative_region_id === (string) $project->knative_region_id);
        if (! $primary || $primary->healthy || $project->globalFailover() !== 'automatic' || ! $project->activeDeployment()->exists()) {
            return;
        }
        if ($primary->unhealthy_since === null || $primary->unhealthy_since->diffInSeconds(now()) < $after) {
            return;
        }

        $target = $this->target($project, $rows->all());
        if ($target?->knativeRegion === null) {
            return;
        }

        Log::warning('Global project: automatic failover', ['project' => $project->slug, 'from' => $project->knativeRegion?->name, 'to' => $target->knativeRegion->name]);
        $this->start($project, $target->knativeRegion, 'failover', automatic: true);
    }

    /**
     * The fenced region answers again: its triggers stop, its data is rebuilt from the new primary,
     * the active version is rolled out to it; it serves again once ready (and its data streams).
     */
    public function rejoin(Project $project, ProjectRegion $row): void
    {
        $region = $row->knativeRegion;
        if (! $region) {
            return;
        }

        $this->triggers->standby($project, $region);
        foreach ($this->replicatedBindings($project) as $binding) {
            if (! $this->data->rejoin($binding, $region)) {
                return;
            }
        }

        $row->update(['status' => ProjectRegion::STATUS_PENDING, 'fenced_at' => null, 'triggers_active' => false, 'status_message' => __('api.global.rejoining')]);
        /** @var Deployment|null $active */
        $active = $project->activeDeployment()->first();
        if ($active) {
            app(GlobalReplicaDeployer::class)->startRollout($project, $row, $active);
        }
        SyncProjectBindingsJob::dispatch((string) $project->getKey());
        $this->audit('project.global.rejoin', $project, ['region' => $region->name]);
    }

    /**
     * The project's primary is $to: its row first in HA order, the triggers there, every region's
     * env (writes to the new home), the DNS answer. $fence: the old primary leaves the answers until
     * it rejoins.
     */
    public function movePrimary(Project $project, KnativeRegion $to, bool $fence): void
    {
        $fromId = (string) $project->knative_region_id;
        $rows = ProjectRegion::query()->where('project_id', $project->getKey())->get()->keyBy(fn (ProjectRegion $r) => (string) $r->knative_region_id);
        $target = $rows->get((string) $to->getKey());
        $old = $rows->get($fromId);

        $project->update(['knative_region_id' => $to->getKey()]);
        $project->setRelation('knativeRegion', $to);

        if ($target && $old) {
            // the old primary takes the target's place in the HA order
            $old->update(['position' => $target->position]);
            $target->update(['position' => 0, 'status_message' => null, 'unhealthy_since' => null]);
        }
        if ($old && $fence) {
            $old->update([
                'status' => ProjectRegion::STATUS_FENCED,
                'fenced_at' => now(),
                'rollout_token' => null,
                'status_message' => __('api.global.fenced', ['region' => $to->name]),
            ]);
        }

        $this->triggers->place($project);
        SyncProjectBindingsJob::dispatch((string) $project->getKey());
        SyncGlobalZoneJob::dispatch();
    }

    /**
     * The project's bindings that have replica clusters (dedicated Postgres).
     *
     * @return list<Binding>
     */
    private function replicatedBindings(Project $project): array
    {
        if (! RegionalData::tableReady()) {
            return [];
        }
        $ids = BindingAttachment::query()->where('project_id', $project->getKey())->whereNull('environment_id')->pluck('binding_id');

        return Binding::query()->whereIn('id', $ids)->with('region')->get()
            ->filter(fn (Binding $b) => $b->isDedicated() && RegionalData::replicated($b))
            ->values()->all();
    }

    /**
     * Each region's copy as PowerDNS checks it — the health route of the region's Traefik ("ok"
     * while the Knative Service is ready), all regions at once; a project that only runs a CronJob:
     * the region's cluster API.
     *
     * @param  list<ProjectRegion>  $rows
     * @return array<string, bool> row id => healthy
     */
    private function healthOf(Project $project, array $rows, bool $cronOnly): array
    {
        $out = [];
        $targets = [];
        $host = app(GlobalDnsZone::class)->healthHost();
        foreach ($rows as $row) {
            $region = $row->knativeRegion;
            if (! $region) {
                continue;
            }
            if ($cronOnly) {
                $out[(string) $row->getKey()] = $this->answers($region);

                continue;
            }
            $address = GlobalDnsServerService::firstAddress($region);
            if ($host === null || $address === null) {
                $out[(string) $row->getKey()] = false;

                continue;
            }
            $targets[(string) $row->getKey()] = 'http://'.(str_contains($address, ':') ? "[{$address}]" : $address).'/global-health/'.$project->slug;
        }

        if ($targets === []) {
            return $out;
        }

        $timeout = max(1, (int) config('global_dns.health.timeout', 2));
        try {
            $responses = Http::pool(function (Pool $pool) use ($targets, $host, $timeout) {
                foreach ($targets as $id => $url) {
                    $pool->as($id)->timeout($timeout)->connectTimeout($timeout)->withHeaders(['Host' => $host])->get($url);
                }
            });
        } catch (Throwable) {
            $responses = [];
        }

        foreach ($targets as $id => $url) {
            $response = $responses[$id] ?? null;
            $out[$id] = $response instanceof Response && $response->status() === 200 && trim($response->body()) === 'ok';
        }

        return $out;
    }

    /** The region's cluster API answers (a fenced region can rejoin). */
    private function answers(?KnativeRegion $region): bool
    {
        if (! $region?->kubeconfig || $region->is_demo) {
            return false;
        }
        try {
            return (new KubernetesClient((string) $region->kubeconfig))->getServerVersion() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** What RunR did on its own (no request): recorded with actor system. */
    private function audit(string $action, Project $project, array $meta): void
    {
        Log::info('global projects: '.$action, ['project' => $project->slug] + $meta);
        app(AuditLogger::class)->record($action, [
            'actor' => 'system',
            'target' => ['type' => 'project', 'id' => $project->getKey(), 'name' => $project->name],
            'metadata' => $meta,
        ]);
    }

    private function rowMessage(Project $project, KnativeRegion $region, string $message): void
    {
        ProjectRegion::query()->where('project_id', $project->getKey())->where('knative_region_id', $region->getKey())
            ->update(['status_message' => mb_substr($message, 0, 2000)]);
    }
}
