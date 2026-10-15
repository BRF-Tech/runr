<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Mail\ResourceThresholdMail;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\User;
use App\Services\Concerns\ResourceComputationTrait;
use App\Services\Helpers\RegionCapacity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class ResourceThresholdService
{
    use ResourceComputationTrait;

    public const WARNING = 60;

    public const CRITICAL = 80;

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
        $summary = RegionCapacity::summary($region, $this->regionUsage($region));

        $this->checkResource($region, $summary['cpu_m'], 'cpu');
        $this->checkResource($region, $summary['memory_mb'], 'memory');
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

    protected function sendMail(Notification $notification): void
    {
        $recipients = $this->getMailRecipients();
        if ($recipients->isEmpty()) {
            return;
        }

        Mail::to($recipients)->queue(new ResourceThresholdMail($notification));
    }

    protected function getMailRecipients()
    {
        return User::all();
    }

    protected function createNotification(
        KnativeRegion $region,
        string $type,
        string $level,
        float $percentage
    ): Notification {
        $threshold = $level === 'critical' ? self::CRITICAL : self::WARNING;
        $typeLabel = $type === 'cpu' ? 'CPU' : 'Memory';

        return Notification::create([
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
        ]);
    }
}
