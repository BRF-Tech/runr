<?php

namespace Tests\Feature\WhiteLabel\Concerns;

use App\Models\User;
use App\Services\WhiteLabel\PlatformSettings;
use Illuminate\Support\Facades\Schema;

/**
 * Single-tenant white-label tests: users.is_admin (a Tenancy module migration, applied in both
 * editions) and user helpers.
 */
trait CoreWhiteLabel
{
    protected function setUpCoreWhiteLabel(): void
    {
        if (! Schema::hasColumn('users', 'is_admin')) {
            (require base_path('Modules/Tenancy/Database/Migrations/2026_03_10_100001_add_is_admin_to_users.php'))->up();
        }

        config(['app.url' => 'https://console.example.com', 'app.frontend_url' => 'https://console.example.com']);

        // secure_settings are encrypted with the app key
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        app(PlatformSettings::class)->flush();
    }

    protected function coreUser(bool $admin = false, array $attrs = []): User
    {
        static $n = 0;
        $n++;

        $user = User::factory()->create(array_merge(['username' => 'core-user-'.$n.'-'.uniqid()], $attrs));
        if ($admin) {
            $user->forceFill(['is_admin' => true])->save();
        }

        return $user->fresh();
    }

    protected function setting(string $key, mixed $value): void
    {
        app(PlatformSettings::class)->set($key, $value);
        app(PlatformSettings::class)->flush();
    }
}
