<?php

namespace App\Console\Commands;

use App\Support\Auth\LoginTokens;
use Illuminate\Console\Command;

/**
 * Token housekeeping (scheduled daily, routes/console.php): sign-in sessions that ended (expired
 * or older than the sign-in session lifetime) and API tokens that expired more than 30 days ago —
 * the console lists an expired API token as expired until then (docs/security.md).
 */
class PruneTokensCommand extends Command
{
    protected $signature = 'tokens:prune';

    protected $description = 'Remove ended sign-in sessions and API tokens expired for more than 30 days';

    public function handle(): int
    {
        $removed = LoginTokens::prune();

        $this->info("Removed {$removed['sessions']} sign-in session(s) and {$removed['tokens']} expired API token(s).");

        return self::SUCCESS;
    }
}
