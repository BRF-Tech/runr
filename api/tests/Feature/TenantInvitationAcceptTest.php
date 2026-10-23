<?php

namespace Tests\Feature;

use App\Models\PendingSignup;
use App\Models\User;
use App\Support\Auth\AccountLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as Router;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantInvitation;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Accepting a tenant invitation (docs/tenancy.md#members): the invited address names an existing
 * account in any letter case, as the sign-in does; a new account gets a user name no account has
 * — letters, digits, "-" and "_" — instead of whatever stood before the "@".
 */
class TenantInvitationAcceptTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();

        // The suite runs with the Tenancy module off: its public routes the way it registers them
        Router::middleware('api')->prefix('api')->group(base_path('Modules/Tenancy/routes/public.php'));
        Router::getRoutes()->refreshNameLookups();
        Router::getRoutes()->refreshActionLookups();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    /** An open invitation to $email; returns its plain token. */
    private function invite(string $email): string
    {
        [$plain, $hash] = TenantInvitation::newToken();
        TenantInvitation::create([
            'tenant_id' => $this->tenant->id, 'email' => $email, 'role' => 'developer', 'token' => $hash, 'expires_at' => now()->addDays(7),
        ]);

        return $plain;
    }

    private function members(): array
    {
        return DB::connection('central')->table('tenant_users')->where('tenant_id', $this->tenant->id)->pluck('role', 'user_id')->all();
    }

    public function test_an_address_in_other_letters_is_the_existing_account(): void
    {
        $ada = User::factory()->create(['username' => 'ada', 'email' => 'ada@example.test', 'password' => Hash::make('ada-password')]);
        $token = $this->invite('Ada@Example.test');

        $this->getJson("/api/invitations/{$token}")->assertOk()->assertJsonPath('has_account', true);
        // The account's password, not a new account's name and password
        $this->postJson("/api/invitations/{$token}/accept", ['name' => 'Ada', 'password' => 'wrong-password'])->assertStatus(422);
        $this->postJson("/api/invitations/{$token}/accept", ['password' => 'ada-password'])->assertOk()
            ->assertJsonPath('user.id', $ada->id);

        $this->assertSame(1, User::query()->count());
        $this->assertSame([$ada->id => 'developer'], $this->members());
    }

    public function test_a_new_account_gets_a_free_user_name(): void
    {
        // "ada" is taken in other letters, "ada-2" by a signup that waits for its verification
        User::factory()->create(['username' => 'ADA', 'email' => 'someone@example.test']);
        PendingSignup::query()->create(['email' => 'other@example.test', 'username' => 'ada-2', 'name' => 'O', 'password' => 'x',
            'token_hash' => 'h', 'expires_at' => now()->addHour(), 'send_count' => 1]);
        $token = $this->invite('Ada@Example.test');

        $this->getJson("/api/invitations/{$token}")->assertOk()->assertJsonPath('has_account', false);
        $this->postJson("/api/invitations/{$token}/accept", ['name' => 'Ada Lovelace', 'password' => 'secret-password'])->assertOk()
            ->assertJsonPath('user.username', 'ada-3')
            ->assertJsonPath('user.email', 'ada@example.test');

        $user = User::query()->where('username', 'ada-3')->sole();
        $this->assertSame([$user->id => 'developer'], $this->members());
    }

    public function test_the_user_name_keeps_letters_digits_dashes_and_underscores(): void
    {
        $this->assertSame('omer-o-brien-ci_bot', AccountLookup::usernameFor("Ömer.O'Brien+ci_bot@example.test"));
        $this->assertSame('user', AccountLookup::usernameFor('+++@example.test'));
        $this->assertSame(64, strlen(AccountLookup::usernameFor(str_repeat('a', 80).'@example.test')));

        User::factory()->create(['username' => str_repeat('a', 64), 'email' => 'long@example.test']);
        $this->assertSame(str_repeat('a', 62).'-2', AccountLookup::usernameFor(str_repeat('a', 80).'@example.test'));
        foreach (['omer-o-brien-ci_bot', 'user', str_repeat('a', 62).'-2'] as $name) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $name, 'alpha_dash, as the registration asks for');
        }
    }

    public function test_an_address_several_accounts_share_in_other_letters_is_refused(): void
    {
        User::factory()->create(['username' => 'bob1', 'email' => 'Bob@example.test']);
        User::factory()->create(['username' => 'bob2', 'email' => 'BOB@example.test']);
        $token = $this->invite('bob@example.test');

        $this->getJson("/api/invitations/{$token}")->assertOk()->assertJsonPath('has_account', true);
        $this->postJson("/api/invitations/{$token}/accept", ['name' => 'Bob', 'password' => 'secret-password'])->assertStatus(409)
            ->assertJsonPath('code', 'email_ambiguous');

        $this->assertSame(2, User::query()->count(), 'no third account');
        $this->assertSame([], $this->members());
    }
}
