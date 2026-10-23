<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * api/config/app.php had a hard-coded 'admin123' fallback for ADMIN_PASSWORD,
 * so any install that didn't set the env var (secret.create=false, runtime/lib/deploy.sh) got a
 * known default administrator password. There is no fallback in config anymore — AdminSeeder
 * generates a random password instead when ADMIN_PASSWORD is unset.
 */
class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_a_random_password_when_admin_password_is_unset(): void
    {
        config(['app.admin.password' => null]);

        (new AdminSeeder)->run();

        $admin = User::where('username', config('app.admin.username'))->firstOrFail();
        $this->assertFalse(Hash::check('admin123', $admin->password), 'must not fall back to admin123');
        $this->assertFalse(Hash::check('', $admin->password));
    }

    public function test_generated_password_never_reaches_the_log(): void
    {
        config(['app.admin.password' => null]);

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        (new AdminSeeder)->run();

        $admin = User::where('username', config('app.admin.username'))->firstOrFail();
        $this->assertNotEmpty($logged, 'the missing ADMIN_PASSWORD is still reported');

        foreach ($logged as $line) {
            preg_match_all('/[A-Za-z0-9]{20}/', $line, $m);
            foreach ($m[0] as $candidate) {
                $this->assertFalse(Hash::check($candidate, $admin->password), 'generated admin password was written to the log');
            }
        }
    }

    public function test_uses_admin_password_env_when_set(): void
    {
        config(['app.admin.password' => 'a-strong-configured-password']);

        (new AdminSeeder)->run();

        $admin = User::where('username', config('app.admin.username'))->firstOrFail();
        $this->assertTrue(Hash::check('a-strong-configured-password', $admin->password));
    }

    public function test_does_not_touch_an_existing_admin(): void
    {
        config(['app.admin.password' => 'first-run-password']);
        (new AdminSeeder)->run();
        $admin = User::where('username', config('app.admin.username'))->firstOrFail();
        $originalHash = $admin->password;

        config(['app.admin.password' => 'second-run-password']);
        (new AdminSeeder)->run();

        $admin->refresh();
        $this->assertSame($originalHash, $admin->password);
    }

    /** The sign-in reads an identifier with "@" as an e-mail address first: a user name has none. */
    public function test_refuses_a_user_name_with_at(): void
    {
        config(['app.admin.username' => 'admin@corp', 'app.admin.password' => 'a-strong-configured-password']);

        try {
            (new AdminSeeder)->run();
            $this->fail('the seeder created an administrator whose user name contains "@"');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('ADMIN_USERNAME cannot contain "@"', $e->getMessage());
        }

        $this->assertSame(0, User::query()->count());
    }
}
