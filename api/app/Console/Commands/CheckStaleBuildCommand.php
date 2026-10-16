<?php

namespace App\Console\Commands;

use App\Jobs\CheckBuildReadyJob;
use App\Models\Version;
use Illuminate\Console\Command;

/**
 * Safety net behind CheckBuildReadyJob: a version still "building" a minute after the checks would
 * have given up (the build Job's deadline, executor.build_deadline_seconds, plus their margin) is
 * marked failed — e.g. when the queue lost the check.
 */
class CheckStaleBuildCommand extends Command
{
    protected $signature = 'build:check-stale';

    protected $description = 'Mark builds stuck in "building" past the build deadline as failed';

    /** Seconds after the last change of a building version before it counts as stuck. */
    public static function staleAfterSeconds(): int
    {
        return CheckBuildReadyJob::timeoutSeconds() + 60;
    }

    public function handle(): int
    {
        $count = 0;
        $seconds = self::staleAfterSeconds();
        $minutes = (int) ceil($seconds / 60);

        Version::where('build_status', 'building')
            ->where('updated_at', '<', now()->subSeconds($seconds))
            ->each(function (Version $v) use (&$count, $minutes) {
                $v->update([
                    'build_status' => 'failed',
                    'build_error' => "Build timeout — no completion within {$minutes} minutes",
                    'build_token' => null,
                ]);
                $count++;
            });

        if ($count > 0) {
            $this->info("Marked {$count} stale build(s) as failed.");
        }

        return self::SUCCESS;
    }
}
