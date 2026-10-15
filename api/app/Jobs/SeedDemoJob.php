<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Models\Setting;
use Database\Seeders\DemoSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SeedDemoJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(?string $initiatorId = null)
    {
        $this->initiatorId = $initiatorId;
    }

    public function handle(): void
    {
        $existing = KnativeRegion::where('is_demo', true)->first();
        if ($existing && $existing->projects()->exists()) {
            Setting::set('demo_status', 'idle');

            return;
        }

        (new DemoSeeder)->run();

        Setting::set('demo_status', 'idle');

        $this->broadcastSuccess('demo.seeded', ['demo_status' => 'loaded'], 'Demo data created');
    }

    public function failed(?\Throwable $e): void
    {
        Setting::set('demo_status', 'error');

        $this->broadcastFailure('demo.seed_failed', ['demo_status' => 'error'], $e, 'Demo data creation failed');
    }
}
