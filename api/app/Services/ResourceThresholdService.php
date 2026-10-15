<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Mail\ResourceThresholdMail;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\Concerns\ResourceComputationTrait;
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
        $projects = Project::where('knative_region_id', $region->id)
            ->with(['xaasConfig', 'faasConfig', 'containerConfig'])
            ->get();

        $this->checkResource($region, $projects, 'cpu');
        $this->checkResource($region, $projects, 'memory');
    }

    protected function checkResource(KnativeRegion $region, $projects, string $type): void
    {
        // Projects and running model services (docs/huggingface.md)
        $models = $this->modelServiceUsage($region);
        if ($type === 'cpu') {
            $used = $this->computeUsedCpu($projects) + $models['cpu_m'];
            $total = $region->total_cpu_m;
        } else {
            $used = $this->computeUsedMemory($projects) + $models['memory_mb'];
            $total = $region->total_memory_mb;
        }

        if (! $total || $total <= 0) {
            return;
        }

        $pct = round($used / $total * 100, 1);

        if ($pct < self::WARNING) {
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
