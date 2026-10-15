<?php

namespace App\Jobs\Ai;

use App\Events\PlatformEvent;
use App\Models\AiModelService;
use App\Services\Ai\Models\ModelServiceDeployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Poll a deploying model service until its Knative Service is Ready (or failed) — every 15 s for
 * up to the progress deadline (model downloads and loads take minutes, not seconds). Meanwhile
 * the service shows why its pods do not start (status_reason: CrashLoopBackOff with the
 * engine's last output, ImagePullBackOff, ErrImagePull, OOMKilled, Unschedulable …).
 */
class CheckModelServiceReadyJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const POLL_SECONDS = 15;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public string $serviceId, public int $attempt = 1) {}

    public function handle(ModelServiceDeployer $deployer): void
    {
        $service = AiModelService::with('region')->find($this->serviceId);
        if (! $service || $service->status !== AiModelService::STATUS_DEPLOYING) {
            return;
        }

        try {
            [$ready, $message, $reason] = $deployer->readiness($service);
        } catch (\Throwable $e) {
            [$ready, $message, $reason] = [null, $e->getMessage(), $service->status_reason];
        }

        if ($ready === true) {
            $service->update(['status' => AiModelService::STATUS_READY, 'status_message' => null, 'status_reason' => null]);
            $this->broadcast($service, 'model_service.ready');

            return;
        }

        if ($ready === false || $this->attempt >= self::maxAttempts()) {
            $service->update([
                'status' => AiModelService::STATUS_FAILED,
                'status_message' => mb_substr((string) ($message ?: __('ai.models.not_ready_in_time')), 0, 2000),
                'status_reason' => $reason,
            ]);
            $this->broadcast($service, 'model_service.failed');

            return;
        }

        // Still deploying: why the pods do not start (CrashLoopBackOff, ImagePullBackOff …) or
        // Knative's progress; a problem that is gone takes its message with it
        $changes = [];
        if ($reason !== $service->status_reason) {
            $changes['status_reason'] = $reason;
            if ($message === null) {
                $changes['status_message'] = null;
            }
        }
        if ($message && $message !== $service->status_message) {
            $changes['status_message'] = mb_substr($message, 0, 2000);
        }
        if ($changes !== []) {
            $service->update($changes);
        }

        self::dispatch($this->serviceId, $this->attempt + 1)->delay(now()->addSeconds(self::POLL_SECONDS));
    }

    /** Checks within the progress deadline (default 1800 s). */
    public static function maxAttempts(): int
    {
        $deadline = (string) config('ai.gpu.progress_deadline', '1800s');
        $seconds = (int) (preg_match('/^(\d+)s$/', $deadline, $m) ? $m[1] : (preg_match('/^(\d+)m$/', $deadline, $m) ? (int) $m[1] * 60 : 1800));

        return max(4, intdiv($seconds, self::POLL_SECONDS) + 2);
    }

    private function broadcast(AiModelService $service, string $topic): void
    {
        try {
            event(new PlatformEvent($topic, ['model_service_id' => $service->id, 'name' => $service->name, 'status' => $service->status]));
        } catch (\Throwable) {
            // live update only
        }
    }
}
