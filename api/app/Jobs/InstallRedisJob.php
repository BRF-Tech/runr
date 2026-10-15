<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\KnativeRedisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Encrypted queue payload: carries the Redis password (docs/security.md) */
class InstallRedisJob implements ShouldBeEncrypted, ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public function __construct(
        public KnativeRegion $region,
        public string $mode,
        public ?string $address = null,
        public ?string $password = null,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(KnativeRedisService $redis): void
    {
        $redis->install($this->region, $this->mode, $this->address, $this->password);

        $this->broadcastSuccess('redis.installed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
        ], "Redis configured on {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        $this->region->refresh();
        $time = now()->format('H:i:s');
        $this->region->update([
            'knative_redis_status' => 'error',
            'knative_redis_status_message' => "[{$time}] ERROR: ".($e?->getMessage() ?? 'Unknown error'),
        ]);

        $this->broadcastFailure('redis.install_failed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
        ], $e, "Redis install failed on {$this->region->name}");
    }
}
