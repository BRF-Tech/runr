<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Mail\ResourceThresholdMail;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Concerns\ResourceComputationTrait;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\StoppedCapacity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * The capacity alarm of a region (docs/architecture.md → Region capacity and admission): every
 * minute (resource:check-thresholds) and after a deploy, the fuller of the two budgets is measured
 * against two levels, WARNING (60 %) and CRITICAL (80 %); crossing one raises a resource_threshold
 * notification (in the app, by mail, and to the resource_threshold alert rules), once an hour per
 * region, resource and level.
 *
 * The same levels watch the region with its stopped projects back (StoppedCapacity: a stopped
 * project holds nothing of a pay-as-you-go region): when everything would cross a level if every
 * stopped project were resumed at the same time, the same people are told to add nodes — by how
 * much the region would be exceeded, how much capacity keeps it under the level, and which
 * stopped projects need how much in total (data.basis "with_stopped", its own hourly cooldown).
 *
 * Who is told: single-tenant, every user (a shared notification, and a mail); multi-tenant, the
 * platform administrators for a platform region — whose usage adds up every tenant's and the
 * platform's own, the regions shared with tenants included (TenantResourceThresholdService) — and
 * for a tenant's own region the tenant's members who manage its regions (regions.manage), in the
 * app and by mail. A recipient who switched the resource_threshold mail off gets none.
 *
 * measure() is what the alarm measures; the capacity endpoint shows the same figures.
 */
class ResourceThresholdService
{
    use ResourceComputationTrait;

    public const WARNING = 60;

    public const CRITICAL = 80;

    /** data.basis of the alarm on the region with its stopped projects back */
    public const WITH_STOPPED = 'with_stopped';

    /** The notification type, also the preference type of its channels (in_app, email) */
    public const PREFERENCE_TYPE = 'resource_threshold';

    /** How many of the stopped projects an alarm names (the largest first) */
    private const NAMED_PROJECTS = 10;

    public function checkAllRegions(): void
    {
        KnativeRegion::where('is_active', true)
            ->where(fn ($q) => $q->where('cluster_status', 'online')->orWhere('is_demo', true))
            ->each(fn (KnativeRegion $region) => $this->checkRegion($region));
    }

    public function checkRegion(KnativeRegion $region): void
    {
        // Projects, model services and sandboxes, against the fuller of the two capacity
        // budgets (always-on: the capacity; FaaS: capacity × overcommit factor)
        $measure = $this->measure($region);
        $this->checkMeasured($region, $measure['usage'], $measure['stopped']);
    }

    /**
     * What holds the region (RegionCapacity::usage()) and what its stopped projects would hold
     * again (RegionCapacity::stopped()), as the alarm measures it; the Tenancy module adds up every
     * tenant's for a platform region.
     *
     * @return array{usage: array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}, stopped: array{demand: array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}, projects: list<array<string, mixed>>}}
     */
    public function measure(KnativeRegion $region): array
    {
        return ['usage' => $this->regionUsage($region), 'stopped' => RegionCapacity::stopped((string) $region->getKey())];
    }

    /**
     * Both alarms of a region: what holds it, and what would hold it with its stopped projects back.
     *
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $usage  RegionCapacity::usage()
     * @param  array{demand: array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}, projects: list<array<string, mixed>>}  $stopped  RegionCapacity::stopped()
     */
    protected function checkMeasured(KnativeRegion $region, array $usage, array $stopped): void
    {
        $summary = RegionCapacity::summary($region, $usage);

        $this->checkResource($region, $summary['cpu_m'], 'cpu');
        $this->checkResource($region, $summary['memory_mb'], 'memory');
        $this->checkWithStopped($region, $this->withStopped($region, $usage, $stopped), $stopped);
    }

    /**
     * The region with its stopped projects back, as the capacity endpoint shows it next to the
     * budgets (RegionCapacity::withStopped()): per resource also the alarm level it would reach
     * (null below WARNING) and the capacity to add to stay under that level (add_capacity); held —
     * the region keeps its stopped projects' reservations (a fixed region), so nothing is
     * released; stopped_projects — the largest of them.
     *
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $usage
     * @param  array{demand: array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}, projects: list<array<string, mixed>>}  $stopped
     * @return array<string, mixed>
     */
    public function withStopped(KnativeRegion $region, array $usage, array $stopped): array
    {
        $figure = RegionCapacity::withStopped($region, $usage, $stopped);
        $limits = RegionCapacity::limits([$region]);
        $factors = RegionCapacity::factors($region);

        foreach (['cpu_m', 'memory_mb'] as $key) {
            $pct = $figure[$key]['utilization'];
            $level = $pct === null || $pct < self::WARNING ? null : ($pct >= self::CRITICAL ? 'critical' : 'warning');
            $figure[$key]['level'] = $level;
            $figure[$key]['add_capacity'] = $level === null ? 0 : RegionCapacity::capacityToAdd(
                $limits['capacity'][$key], $factors[$key], $figure[$key]['always_on_used'], $figure[$key]['faas_used'],
                $level === 'critical' ? self::CRITICAL : self::WARNING,
            );
        }

        return $figure + [
            'held' => StoppedCapacity::holds((string) $region->id),
            'thresholds' => ['warning' => self::WARNING, 'critical' => self::CRITICAL],
            'stopped_projects' => array_slice($stopped['projects'], 0, 20),
        ];
    }

    /** @param  array{capacity: int, always_on_used: int, faas_overcommit: float, faas_used: int}  $budgets  one resource of RegionCapacity::summary() */
    protected function checkResource(KnativeRegion $region, array $budgets, string $type): void
    {
        $pct = RegionCapacity::utilization($budgets);

        if ($pct === null || $pct < self::WARNING) {
            return;
        }

        $level = $pct >= self::CRITICAL ? 'critical' : 'warning';

        $cooldownKey = "notification:threshold:{$region->id}:{$type}:{$level}";
        if (Cache::has($cooldownKey)) {
            return;
        }

        $notification = $this->createNotification($region, $type, $level, $pct);

        Cache::put($cooldownKey, true, now()->addHour());

        NotificationCreated::dispatch($notification);
        $this->sendMail($notification);
    }

    /**
     * The alarm on the region with its stopped projects back: per resource the stopped projects
     * need, once its level is reached (an hour's cooldown of its own per region, resource and level).
     *
     * @param  array<string, mixed>  $figure  withStopped()
     * @param  array{projects: list<array<string, mixed>>}  $stopped
     */
    protected function checkWithStopped(KnativeRegion $region, array $figure, array $stopped): void
    {
        if ($stopped['projects'] === []) {
            return;
        }

        foreach (['cpu_m' => 'cpu', 'memory_mb' => 'memory'] as $key => $type) {
            $resource = $figure[$key];
            $level = $resource['level'];
            $needs = $resource['stopped_always_on'] + $resource['stopped_faas'];
            if ($level === null || $needs <= 0) {
                continue;
            }

            $cooldownKey = "notification:threshold:{$region->id}:{$type}:{$level}:".self::WITH_STOPPED;
            if (Cache::has($cooldownKey)) {
                continue;
            }

            $notification = $this->createWithStoppedNotification($region, $type, $level, $resource, $stopped['projects']);

            Cache::put($cooldownKey, true, now()->addHour());

            NotificationCreated::dispatch($notification);
            $this->sendMail($notification);
        }
    }

    protected function sendMail(Notification $notification): void
    {
        // the recipients who did not switch the resource_threshold mail off
        $recipients = collect($this->getMailRecipients())
            ->filter(fn (User $user) => $user->email && $this->wantsMail($user))
            ->values();
        if ($recipients->isEmpty()) {
            return;
        }

        Mail::to($recipients)->queue(new ResourceThresholdMail($notification));
    }

    /** @return iterable<User> */
    protected function getMailRecipients()
    {
        return User::all();
    }

    /**
     * The user's preference for the resource_threshold mail (PUT /account/notification-preferences),
     * where the alarm is raised — an organization keeps its members' preferences in its own schema;
     * on by default.
     */
    protected function wantsMail(User $user): bool
    {
        try {
            $preference = NotificationPreference::query()->where('user_id', $user->getKey())
                ->where('type', self::PREFERENCE_TYPE)->where('channel', 'email')->first();
        } catch (QueryException) {
            return true;
        }

        return $preference === null || (bool) $preference->enabled;
    }

    protected function createNotification(
        KnativeRegion $region,
        string $type,
        string $level,
        float $percentage
    ): Notification {
        $threshold = $level === 'critical' ? self::CRITICAL : self::WARNING;
        $typeLabel = $type === 'cpu' ? 'CPU' : 'Memory';

        return $this->tagNotification(Notification::create([
            'type' => 'resource_threshold',
            'level' => $level,
            'title' => "{$region->name}: {$typeLabel} {$percentage}%",
            'data' => [
                'region_id' => $region->id,
                'region_name' => $region->name,
                'resource_type' => $type,
                'percentage' => $percentage,
                'threshold' => $threshold,
            ],
        ]));
    }

    /**
     * @param  array<string, mixed>  $resource  one resource of withStopped()
     * @param  list<array<string, mixed>>  $projects  RegionCapacity::stopped()['projects']
     */
    protected function createWithStoppedNotification(KnativeRegion $region, string $type, string $level, array $resource, array $projects): Notification
    {
        $key = $type === 'cpu' ? 'cpu_m' : 'memory_mb';
        $threshold = $level === 'critical' ? self::CRITICAL : self::WARNING;
        $typeLabel = $type === 'cpu' ? 'CPU' : 'Memory';
        $unit = fn (int $value) => $type === 'cpu' ? "{$value}m" : "{$value} MB";
        $percentage = (float) $resource['utilization'];

        // the stopped projects that need some of this resource, the largest first
        $needing = array_values(array_filter($projects, fn (array $p) => (int) $p[$key] > 0));
        usort($needing, fn (array $a, array $b) => (int) $b[$key] <=> (int) $a[$key]);
        $stoppedNeed = (int) $resource['stopped_always_on'] + (int) $resource['stopped_faas'];
        $named = array_slice($needing, 0, self::NAMED_PROJECTS);

        $params = [
            'count' => count($needing),
            'stopped' => $unit($stoppedNeed),
            'resource' => $typeLabel,
            'percentage' => $percentage,
            'threshold' => $threshold,
            'budget' => __('api.capacity_alarm.budget_'.($resource['budget'] ?? RegionCapacity::FAAS)),
            'exceeded' => $unit((int) $resource['exceeded_by']),
            'add' => $unit((int) $resource['add_capacity']),
            'projects' => implode(', ', array_map(fn (array $p) => $p['name'].' ('.$unit((int) $p[$key]).')', $named))
                .(count($needing) > count($named) ? ' '.__('api.capacity_alarm.more', ['count' => count($needing) - count($named)]) : ''),
        ];

        return $this->tagNotification(Notification::create([
            'type' => 'resource_threshold',
            'level' => $level,
            'title' => "{$region->name}: {$typeLabel} {$percentage}% with stopped projects",
            'body' => (string) __((int) $resource['exceeded_by'] > 0 ? 'api.capacity_alarm.with_stopped_exceeded' : 'api.capacity_alarm.with_stopped', $params),
            'data' => [
                'region_id' => $region->id,
                'region_name' => $region->name,
                'resource_type' => $type,
                'percentage' => $percentage,
                'threshold' => $threshold,
                'basis' => self::WITH_STOPPED,
                'budget' => $resource['budget'],
                'stopped_projects' => count($needing),
                // what the stopped projects need of this resource, by how much the fuller budget
                // would be exceeded, and the capacity that keeps it under the threshold
                'stopped' => $stoppedNeed,
                'exceeded_by' => (int) $resource['exceeded_by'],
                'add_capacity' => (int) $resource['add_capacity'],
                'unit' => $type === 'cpu' ? 'm' : 'MB',
                'projects' => array_map(fn (array $p) => ['id' => $p['id'], 'name' => $p['name'], 'amount' => (int) $p[$key]], $named),
            ],
        ]));
    }

    /** A notification of this service as it is stored; the Tenancy module adds the tenant. */
    protected function tagNotification(Notification $notification): Notification
    {
        return $notification;
    }
}
