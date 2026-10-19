<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The first administrator of an install: the account of the admin settings (ADMIN_USERNAME,
 * ADMIN_EMAIL, ADMIN_PASSWORD), created while the install has no administrator. The rules are the
 * ones of the Helm chart's bootstrap job (helm/templates/bootstrap-job.yaml); the Compose migrator
 * runs this command (BOOTSTRAP_ADMIN=true, docker/entrypoint.sh). Nothing happens when
 *   - the account ADMIN_USERNAME exists,
 *   - another administrator exists (a user with is_admin; without the is_admin column: any user),
 *   - ADMIN_EMAIL belongs to another account.
 * Existing accounts, passwords and settings are never changed, so it can run on every migration.
 *
 * Without ADMIN_PASSWORD a random password is generated and printed once, on this console only:
 * it is never logged.
 */
class BootstrapAdminCommand extends Command
{
    protected $signature = 'runr:bootstrap-admin';

    protected $description = 'Create the first administrator account (ADMIN_USERNAME / ADMIN_EMAIL / ADMIN_PASSWORD) while the install has none';

    public function handle(): int
    {
        $username = (string) config('app.admin.username');
        $email = (string) config('app.admin.email');

        if (User::where('username', $username)->exists()) {
            $this->line("Administrator account {$username} exists, nothing to do.");

            return self::SUCCESS;
        }

        $flag = Schema::hasColumn((new User)->getTable(), 'is_admin');
        if ($flag ? User::where('is_admin', true)->exists() : User::count() > 0) {
            $this->line('Another administrator account exists, nothing to do.');

            return self::SUCCESS;
        }

        if (User::where('email', $email)->exists()) {
            $this->warn("The e-mail address {$email} belongs to another account: administrator not created (set ADMIN_EMAIL to another address).");

            return self::SUCCESS;
        }

        $configured = (string) config('app.admin.password');
        $password = $configured !== '' ? $configured : Str::random(20);
        config(['app.admin.password' => $password]);
        try {
            (new AdminSeeder)->run();
        } finally {
            config(['app.admin.password' => $configured !== '' ? $configured : null]);
        }

        $user = User::where('username', $username)->firstOrFail();
        if ($flag) {
            $user->forceFill(['is_admin' => true])->save();
        }

        $this->info("Administrator account created: {$username} ({$email})");
        if ($configured === '') {
            $this->line("Password (generated, shown only this once): {$password}");
        } else {
            $this->line('Password: the ADMIN_PASSWORD you set.');
        }

        return self::SUCCESS;
    }
}
