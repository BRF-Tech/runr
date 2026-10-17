<?php

namespace App\Console\Commands;

use App\Support\Auth\LoginTokens;
use Illuminate\Console\Command;

/**
 * Token housekeeping (scheduled daily, routes/console.php): sign-in sessions that ended (expired
 * or older than the sign-in session lifetime), API tokens that expired more than 30 days ago —
 * the console lists an expired API token as expired until then — and the tokens of users that no
 * longer exist (docs/security.md); in the multi-tenant edition also the tokens organizations
 * refuse for good and the organizations' copies of deleted users (AccountPruner).
 */
class PruneTokensCommand extends Command
{
    protected $signature = 'tokens:prune';

    protected $description = 'Remove ended sign-in sessions, API tokens expired for more than 30 days and tokens of deleted users';

    public function handle(): int
    {
        $removed = LoginTokens::prune();

        $this->info("Removed {$removed['sessions']} sign-in session(s), {$removed['tokens']} expired API token(s) and {$removed['orphans']} token(s) of deleted users.");
        foreach ($removed['more'] as $label => $count) {
            $this->info("Removed {$count} {$label}.");
        }

        return self::SUCCESS;
    }
}
