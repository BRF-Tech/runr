<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Auth\LoginIdentifier;
use App\Support\HorizonAccess;
use App\Support\PlatformConnection;
use Illuminate\Console\Command;

/**
 * A single-use sign-in link to the Horizon dashboard for a platform administrator or, in the
 * single-tenant edition, an Owner (App\Support\HorizonAccess). Run where the operator has cluster
 * access:
 *
 *   kubectl -n runr exec deploy/runr-api -- php artisan runr:horizon-link [--user=<name or e-mail>]
 *
 * The link is printed to this console only; it is never logged.
 */
class HorizonLinkCommand extends Command
{
    protected $signature = 'runr:horizon-link
                {--user= : User name or e-mail of the administrator (default: the oldest one)}
                {--minutes=5 : How long the link stays valid (1–60)}';

    protected $description = 'Print a single-use sign-in link to the Horizon dashboard for a platform administrator (single-tenant: an Owner)';

    public function handle(HorizonAccess $access): int
    {
        // the platform's users: the central connection with tenancy, the default one otherwise
        $connection = PlatformConnection::name();
        $name = (string) $this->option('user');

        $user = null;
        if ($name !== '') {
            // The account the sign-in would take for this user name or e-mail address
            $user = LoginIdentifier::resolve($name, $connection);
        } else {
            foreach (User::on($connection)->orderBy('id')->cursor() as $candidate) {
                if ($access->isAdministrator($candidate)) {
                    $user = $candidate;
                    break;
                }
            }
        }

        if ($user === null || ! $access->isAdministrator($user)) {
            $this->error($name !== ''
                ? "{$name} is neither a platform administrator nor an Owner (or the account is disabled)."
                : 'No enabled platform administrator or Owner exists.');

            return self::FAILURE;
        }

        $link = $access->issue($user, (int) $this->option('minutes'));

        $this->line($link['url']);
        $this->line("Single use, for {$user->username}, valid until {$link['expires_at']->toIso8601String()}.");

        return self::SUCCESS;
    }
}
