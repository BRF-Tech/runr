<?php

namespace App\Jobs;

use App\Models\ExecutorImage;
use App\Services\Runtimes\Patches\RuntimePatchNotifier;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * End-of-life and sunset reminders (scheduled daily, docs/runtimes.md#end-of-life): 13, 4 and 1
 * week(s) before a runtime version ends — its eol_at or sunset_at, the earlier (ExecutorImage::endsAt()) (config runtime_patches.eol_reminder_weeks), the owners of its
 * projects and the organizations' owners and admins hear about it — in-app, by mail too a week
 * ahead. Only the nearest threshold that has been reached is sent (a version that is four weeks
 * from its end when this runs for the first time does not get the 13-week notice as well); each
 * threshold goes out once per user.
 */
class RuntimeEolRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function handle(RuntimePatchNotifier $notifier): void
    {
        $weeks = array_map('intval', (array) config('runtime_patches.eol_reminder_weeks', [13, 4, 1]));
        sort($weeks);
        $mailWeeks = array_map('intval', (array) config('runtime_patches.eol_mail_weeks', [1]));
        $now = now();

        $from = $now->toDateString();
        $until = $now->copy()->addWeeks(max($weeks ?: [13]))->toDateString();

        // The end of a version: its end of life or its sunset, the earlier (docs/runtimes.md#lifecycle)
        $images = ExecutorImage::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereBetween('eol_at', [$from, $until])->orWhereBetween('sunset_at', [$from, $until]))
            ->get();

        foreach ($images as $image) {
            $ends = $image->endsAt();
            if ($ends === null || $ends->toDateString() <= $from || $ends->toDateString() > $until) {
                continue;
            }
            $threshold = self::threshold($ends, $weeks, $now);
            if ($threshold !== null) {
                $notifier->eolReminder($image, $threshold, in_array($threshold, $mailWeeks, true));
            }
        }
    }

    /**
     * The nearest reminder threshold (weeks before $eol) that $now has reached; null when none
     * has (or the end of life passed).
     *
     * @param  list<int>  $weeks  ascending
     */
    public static function threshold(CarbonInterface $eol, array $weeks, Carbon $now): ?int
    {
        if ($eol->lessThanOrEqualTo($now)) {
            return null;
        }

        foreach ($weeks as $w) {
            if ($now->greaterThanOrEqualTo($eol->copy()->subWeeks($w))) {
                return $w;
            }
        }

        return null;
    }
}
