<?php

namespace App\Jobs\Images;

use App\Models\ImageUpload;
use App\Services\Images\ImageUploadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Follows the verification and push of a completed upload (ImageUploadService::refresh) until
 * the upload is completed, failed, back to ready (SHA-256 mismatch) or past the push deadline;
 * one look per POLL_INTERVAL seconds, re-dispatched.
 */
class CheckImageUploadJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public const POLL_INTERVAL = 5;

    public function __construct(public string $uploadId, public int $attempt = 1) {}

    public static function start(ImageUpload $upload): PendingDispatch
    {
        return self::dispatch($upload->id)->delay(now()->addSeconds(self::POLL_INTERVAL));
    }

    public static function maxAttempts(): int
    {
        return (int) ceil(((int) config('images.receiver.push_timeout', 3600) + 600) / self::POLL_INTERVAL);
    }

    public function handle(ImageUploadService $uploads): void
    {
        $upload = ImageUpload::find($this->uploadId);
        if (! $upload || ! $upload->isFinishing()) {
            return;
        }

        $upload = $uploads->refresh($upload);
        if (! $upload->isFinishing()) {
            return;
        }

        if ($this->attempt >= self::maxAttempts()) {
            $uploads->abort($upload); // removes the receiver
            $upload->update(['status' => ImageUpload::STATUS_FAILED, 'message' => __('images.push_timeout')]);

            return;
        }

        self::dispatch($this->uploadId, $this->attempt + 1)->delay(now()->addSeconds(self::POLL_INTERVAL));
    }
}
