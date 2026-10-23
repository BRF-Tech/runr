<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * runr:bootstrap-admin — the first administrator of a Compose install (the migrator runs it with
 * BOOTSTRAP_ADMIN=true): the rules of the Helm chart's bootstrap job, safe to run on every migration.
 */
class BootstrapAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.admin.username' => 'admin',
            'app.admin.email' => 'admin@example.com',
            'app.admin.password' => null,
        ]);
    }

    private function withIsAdminColumn(bool $present): void
    {
        $has = Schema::hasColumn('users', 'is_admin');
        if ($present && ! $has) {
            Schema::table('users', fn (Blueprint $t) => $t->boolean('is_admin')->default(false));
        } elseif (! $present && $has) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
        }
    }

    /** @return array{0: int, 1: string} exit code + console output */
    private function bootstrap(): array
    {
        $code = Artisan::call('runr:bootstrap-admin');

        return [$code, Artisan::output()];
    }

    public function test_creates_the_administrator_with_a_generated_password_printed_once(): void
    {
        $this->withIsAdminColumn(true);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        [$code, $output] = $this->bootstrap();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Administrator account created: admin (admin@example.com)', $output);
        $this->assertSame(1, preg_match('/Password \(generated, shown only this once\): (\S+)/', $output, $m), $output);

        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->assertSame('admin@example.com', $admin->email);
        $this->assertTrue(Hash::check($m[1], $admin->password), 'the printed password signs in');
        $this->assertGreaterThanOrEqual(20, strlen($m[1]));
        $this->assertTrue((bool) $admin->getAttribute('is_admin'));
        $this->assertSame(PermissionCatalog::OWNER, (new SingleTenantOrganization)->memberRole($admin->id));
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($m[1], $line, 'the generated password reached the log');
        }
        $this->assertNull(config('app.admin.password'), 'the generated password is not kept in the configuration');
    }

    public function test_uses_the_configured_password_and_does_not_print_it(): void
    {
        config(['app.admin.password' => 'a-strong-configured-password']);

        [$code, $output] = $this->bootstrap();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Password: the ADMIN_PASSWORD you set.', $output);
        $this->assertStringNotContainsString('a-strong-configured-password', $output);
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->assertTrue(Hash::check('a-strong-configured-password', $admin->password));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->bootstrap();
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $hash = $admin->password;

        config(['app.admin.password' => 'another-password-later']);
        [$code, $output] = $this->bootstrap();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Administrator account admin exists, nothing to do.', $output);
        $this->assertSame($hash, $admin->refresh()->password);
        $this->assertSame(1, User::query()->count());
    }

    public function test_another_administrator_leaves_the_install_alone(): void
    {
        $this->withIsAdminColumn(true);
        $owner = User::factory()->create(['username' => 'ops', 'email' => 'ops@example.com']);
        $owner->forceFill(['is_admin' => true])->save();

        [$code, $output] = $this->bootstrap();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Another administrator account exists, nothing to do.', $output);
        $this->assertFalse(User::query()->where('username', 'admin')->exists());
    }

    public function test_a_user_without_is_admin_does_not_count_as_administrator(): void
    {
        $this->withIsAdminColumn(true);
        User::factory()->create(['username' => 'dev', 'email' => 'dev@example.com']);

        [$code] = $this->bootstrap();

        $this->assertSame(0, $code);
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->assertTrue((bool) $admin->getAttribute('is_admin'));
    }

    /** Single-tenant installs have no is_admin column: any existing user means the install is set up. */
    public function test_without_the_is_admin_column_any_user_leaves_the_install_alone(): void
    {
        $this->withIsAdminColumn(false);
        User::factory()->create(['username' => 'first', 'email' => 'first@example.com']);

        [$code, $output] = $this->bootstrap();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Another administrator account exists, nothing to do.', $output);
        $this->assertSame(1, User::query()->count());
    }

    public function test_without_the_is_admin_column_an_empty_install_gets_the_administrator(): void
    {
        $this->withIsAdminColumn(false);

        [$code] = $this->bootstrap();

        $this->assertSame(0, $code);
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->assertSame(PermissionCatalog::OWNER, (new SingleTenantOrganization)->memberRole($admin->id));
    }

    public function test_an_email_of_another_account_is_not_taken_over(): void
    {
        $this->withIsAdminColumn(true);
        User::factory()->create(['username' => 'someone', 'email' => 'admin@example.com']);

        [$code, $output] = $this->bootstrap();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('belongs to another account: administrator not created', $output);
        $this->assertFalse(User::query()->where('username', 'admin')->exists());
    }

    /** The sign-in matches e-mail addresses in any letter case (LoginIdentifier). */
    public function test_an_email_of_another_account_in_other_capitals_is_not_taken_over(): void
    {
        $this->withIsAdminColumn(true);
        User::factory()->create(['username' => 'someone', 'email' => 'Admin@Example.com']);

        [$code, $output] = $this->bootstrap();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('belongs to another account: administrator not created', $output);
        $this->assertFalse(User::query()->where('username', 'admin')->exists());
    }

    /** The sign-in reads an identifier with "@" as an e-mail address first: a user name has none. */
    public function test_a_user_name_with_at_is_not_created(): void
    {
        $this->withIsAdminColumn(true);
        config(['app.admin.username' => 'admin@corp']);

        [$code, $output] = $this->bootstrap();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('The user name admin@corp contains "@": administrator not created', $output);
        $this->assertSame(0, User::query()->count());
    }

    /** An administrator an earlier release created with such a name is left as it is. */
    public function test_an_existing_administrator_with_at_in_the_user_name_is_left_alone(): void
    {
        $this->withIsAdminColumn(true);
        config(['app.admin.username' => 'admin@corp']);
        $admin = User::factory()->create(['username' => 'admin@corp', 'email' => 'admin@example.com']);

        [$code, $output] = $this->bootstrap();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Administrator account admin@corp exists, nothing to do.', $output);
        $this->assertSame('admin@corp', $admin->refresh()->username);
    }
}
