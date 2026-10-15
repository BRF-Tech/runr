<?php

namespace App\Jobs\Images;

use App\Services\Images\ImageUploadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Hourly: uploads past their TTL are aborted and their receivers removed (docs/images.md). */
class ExpireImageUploadsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function handle(ImageUploadService $uploads): void
    {
        $n = $uploads->expire();
        if ($n > 0) {
            Log::info('Expired image uploads aborted', ['count' => $n]);
        }
    }
}
