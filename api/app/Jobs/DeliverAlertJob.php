<?php

namespace App\Jobs;

use App\Models\Observability\AlertDelivery;
use App\Services\Observability\Alerts\AlertManager;
use App\Services\Observability\TenantScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one alert delivery; a failed send is retried after observability.alerts.delivery_backoff
 * seconds until delivery_tries, then the delivery is marked failed. Errors a retry cannot fix
 * (4xx, blocked URL, invalid configuration) fail at once. The delivery log shows every attempt.
 */
class DeliverAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 60;

    public function __construct(
        public string $deliveryId,
        public ?string $tenantId = null,
    ) {}

    public function tries(): int
    {
        return max(1, (int) config('observability.alerts.delivery_tries', 4));
    }

    public function handle(AlertManager $alerts): void
    {
        TenantScope::run($this->tenantId, function () use ($alerts) {
            $delivery = AlertDelivery::query()->with(['channel', 'event'])->find($this->deliveryId);
            if (! $delivery || $delivery->status !== AlertDelivery::PENDING) {
                return;
            }

            $result = $alerts->send($delivery);
            if ($result->ok) {
                return;
            }

            $attempt = $this->attempts();
            if ($result->retryable && $attempt < $this->tries()) {
                $backoff = (array) config('observability.alerts.delivery_backoff', [30, 120, 600]);
                $delay = (int) ($backoff[min($attempt - 1, count($backoff) - 1)] ?? 60);
                $delivery->forceFill(['next_attempt_at' => now()->addSeconds($delay)])->save();
                $this->release($delay);

                return;
            }

            $delivery->forceFill(['status' => AlertDelivery::FAILED, 'next_attempt_at' => null])->save();
        });
    }
}
