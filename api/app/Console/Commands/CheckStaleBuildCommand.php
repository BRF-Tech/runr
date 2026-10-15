<?php

namespace App\Console\Commands;

use App\Models\Version;
use Illuminate\Console\Command;

class CheckStaleBuildCommand extends Command
{
    protected $signature = 'build:check-stale';

    protected $description = 'Mark builds stuck in "building" for over 6 minutes as failed';

    public function handle(): int
    {
        $count = 0;

        Version::where('build_status', 'building')
            ->where('updated_at', '<', now()->subMinutes(6))
            ->each(function (Version $v) use (&$count) {
                $v->update([
                    'build_status' => 'failed',
                    'build_error' => 'Build timeout — no completion within 6 minutes',
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
