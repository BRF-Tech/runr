<?php

namespace App\Jobs\Environments;

use App\Services\Environments\RegionImages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One check of a version's build into the registry of an environment's region (RegionImages::check),
 * queued again after the delay the check asks for. The token ties the checks to their submission:
 * a newer submission of the same build has checks of its own. Runs in the context of the
 * organization it was dispatched in.
 */
class CheckRegionImageBuildJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public string $regionImageId,
        public string $token,
    ) {}

    public function handle(RegionImages $images): void
    {
        $delay = $images->check($this->regionImageId, $this->token);

        if ($delay !== null) {
            self::dispatch($this->regionImageId, $this->token)->delay(now()->addSeconds($delay));
        }
    }
}
