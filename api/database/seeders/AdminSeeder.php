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

        $password = config('app.admin.password');
        if (! $password) {
            // No ADMIN_PASSWORD was supplied (the Helm chart and the marketplace form always set
            // one; this only happens on a bootstrap path that doesn't, e.g. secret.create=false).
            // A hard-coded fallback would give every such install the same known password, so a
            // random one is generated instead, the same way the chart's own `randAlphaNum 16`
            // default works for secret.create=true. The value never goes to the log: the chart's
            // log channel is stderr, which cluster log shipping collects. It is printed to the
            // seeding process's own STDOUT only (the operator running the seeder sees it once).
            $password = Str::random(20);
            Log::warning('No ADMIN_PASSWORD set — generated a random administrator password (shown only on the console that ran the seeder; set ADMIN_PASSWORD or reset it to choose your own).');
            if (app()->runningInConsole()) {
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
}
