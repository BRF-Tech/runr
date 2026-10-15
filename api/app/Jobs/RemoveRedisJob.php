<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\KnativeRedisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RemoveRedisJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public function __construct(
        public KnativeRegion $region,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(KnativeRedisService $redis): void
    {
        $redis->uninstall($this->region);

        $this->broadcastSuccess('redis.removed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
        ], "Redis removed from {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        $this->region->refresh();
        $time = now()->format('H:i:s');
        $this->region->update([
            'knative_redis_status' => 'error',
            'knative_redis_status_message' => "[{$time}] ERROR: ".($e?->getMessage() ?? 'Unknown error'),
        ]);

        $this->broadcastFailure('redis.remove_failed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
        ], $e, "Redis removal failed on {$this->region->name}");
    }
}
