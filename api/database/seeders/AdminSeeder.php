<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('username', config('app.admin.username'))->exists()) {
            return;
        }

        // The sign-in reads an identifier with "@" as an e-mail address first (LoginIdentifier):
        // a user name never has one
        if (str_contains((string) config('app.admin.username'), '@')) {
            throw new \InvalidArgumentException('ADMIN_USERNAME cannot contain "@": the administrator signs in with ADMIN_EMAIL as well.');
        }

        $password = config('app.admin.password');
        if (! $password) {
            // No ADMIN_PASSWORD was supplied. The installers always supply one: the Helm chart's
            // Secret (or, with secret.create=false, the password its bootstrap Job stores in the
            // Secret runr-admin) and the Compose migrator (runr:bootstrap-admin). A hard-coded
            // fallback would give every other path the same known password, so a random one is
            // generated instead. It never goes to a log — neither the application log (the chart's
            // log channel is stderr, which cluster log shipping collects) nor the output of a
            // process whose stdout is a log (a Kubernetes Job, `docker compose up`): it is shown
            // only on an interactive terminal that ran the seeder, once.
            $password = Str::random(20);
            Log::warning('No ADMIN_PASSWORD set — generated a random administrator password (shown only on the interactive terminal that ran the seeder; set ADMIN_PASSWORD or reset it to choose your own).');
            if ($this->interactiveTerminal()) {
                fwrite(STDOUT, "Generated admin password (ADMIN_PASSWORD was not set): {$password}\n");
            }
        }

        User::create([
            'username' => config('app.admin.username'),
            'name' => 'Admin',
            'email' => config('app.admin.email'),
            'password' => Hash::make($password),
        ]);
    }

    /** STDOUT is a terminal a person reads (not a pipe or a file collected as a log). */
    protected function interactiveTerminal(): bool
    {
        return app()->runningInConsole()
            && ! app()->runningUnitTests()
            && defined('STDOUT')
            && stream_isatty(STDOUT);
    }
}
