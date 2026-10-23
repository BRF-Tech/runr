<?php

namespace Tests\Feature\Auth;

use App\Models\AuditEvent;
use App\Models\PendingSignup;
use App\Models\User;
use App\Support\ApiRateLimits;
use App\Support\Auth\LoginIdentifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\WhiteLabel\Concerns\CoreWhiteLabel;
use Tests\TestCase;

/**
 * Password sign-in with the user name or the e-mail address (docs/security.md, "Password
 * sign-in"), single-tenant edition: POST /api/auth/login takes either in any letter case,
 * an identifier with "@" is an e-mail address before a user name, one identifier names one
 * account at most, a wrong password and an unknown account answer alike, the `auth` rate limiter
 * counts every spelling of an account together, and new user names have no "@" and are unique in
 * any letter case.
 */
class SignInIdentifierTest extends TestCase
{
    use ActsWithRoles, CoreWhiteLabel, RefreshDatabase;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoreWhiteLabel();
        // First user of the install: Owner. The address is stored with capitals, as typed at signup.
        $this->ada = User::factory()->create([
            'username' => 'ada',
            'email' => 'Ada.Lovelace@Example.test',
            'password' => 'correct horse battery',
        ]);
    }

    private function signIn(string $login, string $password = 'correct horse battery', array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->postJson('/api/auth/login', ['login' => $login, 'password' => $password] + $extra);
    }

    private function assertSignedInAs(User $user, TestResponse $response): void
    {
        $response->assertOk()->assertJsonStructure(['user' => ['id'], 'token']);
        $this->assertSame((string) $user->id, (string) $response->json('user.id'));
    }

    /** @return Builder<AuditEvent> */
    private function loginEvents(): Builder
    {
        return AuditEvent::query()->where('action', 'auth.login');
    }

    // ── Resolution ──────────────────────────────────────────────────────────

    public function test_signs_in_with_the_user_name(): void
    {
        $this->assertSignedInAs($this->ada, $this->signIn('ada'));
    }

    public function test_signs_in_with_the_e_mail_address_in_any_letter_case(): void
    {
        foreach (['Ada.Lovelace@Example.test', 'ada.lovelace@example.test', 'ADA.LOVELACE@EXAMPLE.TEST'] as $login) {
            $this->assertSignedInAs($this->ada, $this->signIn($login));
        }
    }

    public function test_the_user_name_matches_in_any_letter_case(): void
    {
        foreach (['ADA', 'Ada'] as $login) {
            $this->assertSignedInAs($this->ada, $this->signIn($login));
        }
    }

    public function test_white_space_around_the_identifier_is_ignored(): void
    {
        $this->assertSignedInAs($this->ada, $this->signIn("  ada \t"));
        $this->assertSignedInAs($this->ada, $this->signIn(' ada.lovelace@example.test '));
        $this->assertSame((string) $this->ada->id, (string) LoginIdentifier::resolve("\n ada.lovelace@example.test  ")?->id);
    }

    public function test_a_wrong_password_and_an_unknown_account_answer_alike(): void
    {
        $wrongPassword = $this->signIn('ada', 'not the password')->assertUnauthorized()->json();
        $wrongPasswordByMail = $this->signIn('ada.lovelace@example.test', 'not the password')->assertUnauthorized()->json();
        $unknownName = $this->signIn('nobody')->assertUnauthorized()->json();
        $unknownMail = $this->signIn('nobody@example.test')->assertUnauthorized()->json();

        $this->assertSame(['message' => __('api.auth.login_failed')], $wrongPassword);
        $this->assertSame($wrongPassword, $wrongPasswordByMail);
        $this->assertSame($wrongPassword, $unknownName);
        $this->assertSame($wrongPassword, $unknownMail);
    }

    public function test_an_identifier_with_at_is_an_e_mail_address_before_a_user_name(): void
    {
        // Accounts of earlier releases whose user names hold an "@" (created without validation)
        $legacy = User::factory()->create(['username' => 'carol@legacy.test', 'email' => 'carol@example.test', 'password' => 'carol-password']);
        $clash = User::factory()->create(['username' => 'ada.lovelace@example.test', 'email' => 'other@example.test', 'password' => 'other-password']);

        // No account has the address: the user name signs in, in any letter case
        $this->assertSignedInAs($legacy, $this->signIn('carol@legacy.test', 'carol-password'));
        $this->assertSignedInAs($legacy, $this->signIn('CAROL@legacy.test', 'carol-password'));

        // The address is Ada's: the identifier names Ada alone, the other account's password does not open it
        $this->signIn('ada.lovelace@example.test', 'other-password')->assertUnauthorized();
        $this->assertSignedInAs($this->ada, $this->signIn('ada.lovelace@example.test'));
        // That account signs in with its e-mail address
        $this->assertSignedInAs($clash, $this->signIn('other@example.test', 'other-password'));
    }

    public function test_accounts_that_differ_in_letter_case_only_never_share_an_identifier(): void
    {
        // Possible before 0.6.0: the e-mail addresses and the user names were unique case-sensitively
        $lower = User::factory()->create(['username' => 'sam', 'email' => 'dup@example.test', 'password' => 'lower-password']);
        $upper = User::factory()->create(['username' => 'Sam', 'email' => 'DUP@example.test', 'password' => 'upper-password']);

        // The exact spelling names its account only
        $this->assertSignedInAs($lower, $this->signIn('dup@example.test', 'lower-password'));
        $this->assertSignedInAs($upper, $this->signIn('DUP@example.test', 'upper-password'));
        $this->signIn('dup@example.test', 'upper-password')->assertUnauthorized();
        $this->assertSignedInAs($lower, $this->signIn('sam', 'lower-password'));
        $this->assertSignedInAs($upper, $this->signIn('Sam', 'upper-password'));
        $this->signIn('Sam', 'lower-password')->assertUnauthorized();

        // A third spelling names neither
        $this->signIn('Dup@Example.test', 'lower-password')->assertUnauthorized();
        $this->signIn('Dup@Example.test', 'upper-password')->assertUnauthorized();
        $this->signIn('SAM', 'lower-password')->assertUnauthorized();
        $this->signIn('SAM', 'upper-password')->assertUnauthorized();
        $this->assertNull(LoginIdentifier::resolve('SAM'));
    }

    public function test_an_overlong_identifier_is_refused(): void
    {
        $this->signIn(str_repeat('a', LoginIdentifier::MAX_LENGTH + 1))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('login');
        $this->assertNull(LoginIdentifier::resolve(str_repeat('a', LoginIdentifier::MAX_LENGTH + 1)));
    }

    public function test_a_disabled_account_is_refused_by_user_name_and_e_mail_address(): void
    {
        $this->ada->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();

        $this->signIn('ada')->assertForbidden()->assertJsonPath('message', __('api.account.account_disabled'));
        $this->signIn('ADA.LOVELACE@example.test')->assertForbidden()->assertJsonPath('message', __('api.account.account_disabled'));
    }

    public function test_the_second_factor_follows_either_identifier(): void
    {
        $this->ada->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-one', 'recovery-code-two'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->signIn('ada.lovelace@example.test')->assertStatus(202)->assertExactJson(['requires_2fa' => true]);
        $this->signIn('ADA')->assertStatus(202)->assertExactJson(['requires_2fa' => true]);

        $this->assertSignedInAs($this->ada, $this->signIn('ada.lovelace@example.test', extra: ['two_factor_code' => 'recovery-code-one']));
        $this->assertSignedInAs($this->ada, $this->signIn('Ada', extra: ['two_factor_code' => 'recovery-code-two']));
        // Recovery codes work once
        $this->signIn('ada', extra: ['two_factor_code' => 'recovery-code-one'])->assertUnprocessable();
    }

    // ── Rate limit ──────────────────────────────────────────────────────────

    public function test_the_rate_limit_counts_the_user_name_and_the_e_mail_address_together(): void
    {
        config(['ratelimit.auth.per_minute' => 3]);
        $bob = User::factory()->create(['username' => 'bob', 'email' => 'bob@example.test', 'password' => 'bob-password']);

        $this->signIn('ada', 'wrong-1')->assertUnauthorized();
        $this->signIn('Ada.Lovelace@Example.test', 'wrong-2')->assertUnauthorized();
        $this->signIn('ADA', 'wrong-3')->assertUnauthorized();

        // Switching identifiers does not reset the budget, not even with the right password
        $this->signIn('ada.lovelace@example.test')->assertStatus(429)->assertHeader('Retry-After')
            ->assertJsonStructure(['message', 'retry_after']);
        $this->signIn('ada')->assertStatus(429);

        // Another account from the same address has its own budget
        $this->assertSignedInAs($bob, $this->signIn('BOB@example.test', 'bob-password'));
    }

    public function test_the_rate_limit_counts_an_unknown_identifier_in_any_letter_case_together(): void
    {
        config(['ratelimit.auth.per_minute' => 2]);

        $this->signIn('ghost@example.test')->assertUnauthorized();
        $this->signIn('Ghost@Example.TEST')->assertUnauthorized();
        $this->signIn(' GHOST@EXAMPLE.TEST ')->assertStatus(429);
    }

    public function test_the_rate_limit_key_names_the_account(): void
    {
        $key = fn (string $login) => ApiRateLimits::authKey(
            Request::create('/api/auth/login', 'POST', ['login' => $login], server: ['REMOTE_ADDR' => '198.51.100.4'])
        );

        $account = 'auth:198.51.100.4|account:'.$this->ada->id;
        $this->assertSame($account, $key('ada'));
        $this->assertSame($account, $key('ADA'));
        $this->assertSame($account, $key('ada.lovelace@example.test'));
        $this->assertSame($account, $key(' Ada.Lovelace@Example.test '));
        // No account: the identifier, trimmed and lower-cased
        $this->assertSame('auth:198.51.100.4|nobody@example.test', $key(' NoBody@Example.test '));
    }

    // ── Audit ───────────────────────────────────────────────────────────────

    public function test_sign_ins_with_either_identifier_are_audited(): void
    {
        $this->signIn('ADA.LOVELACE@EXAMPLE.TEST')->assertOk();
        $this->signIn('ada')->assertOk();

        $successes = $this->loginEvents()->where('result', 'success')->get();
        $this->assertCount(2, $successes);
        foreach ($successes as $event) {
            $this->assertSame('user', $event->actor_type);
            $this->assertSame((string) $this->ada->id, $event->actor_id);
            $this->assertSame('password', $event->metadata['method']);
        }

        // A failure records the identifier as typed, never the password
        $this->signIn('Ada', 'Wrong-Pa55word!')->assertUnauthorized();
        $this->signIn('nobody@example.test', 'Wrong-Pa55word!')->assertUnauthorized();

        $failures = $this->loginEvents()->where('result', 'failure')->get()->keyBy('actor_name');
        $this->assertEqualsCanonicalizing(['Ada', 'nobody@example.test'], $failures->keys()->all());
        foreach ($failures as $event) {
            $this->assertSame('anonymous', $event->actor_type);
            $this->assertSame('invalid_credentials', $event->metadata['reason']);
            $this->assertSame(401, $event->status_code);
            $this->assertStringNotContainsString('Wrong-Pa55word!', json_encode($event->toPublicArray()));
        }
    }

    // ── New user names and e-mail addresses ─────────────────────────────────

    public function test_a_new_user_name_cannot_contain_at(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Jane', 'username' => 'jane@corp', 'email' => 'jane@example.test',
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');

        $this->signedIn($this->ada)->postJson('/api/users', [
            'name' => 'Vera', 'username' => 'vera@corp', 'email' => 'vera@example.test', 'password' => 'secret-pass',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');

        $this->signedIn($this->ada)->patchJson('/api/account/profile', ['username' => 'ada@home'])
            ->assertUnprocessable()->assertJsonValidationErrors('username');

        $this->assertSame(0, User::query()->where('username', 'like', '%@%')->count());
    }

    public function test_an_existing_user_name_with_at_keeps_working(): void
    {
        $legacy = User::factory()->create(['username' => 'dave@legacy', 'email' => 'dave@example.test', 'password' => 'dave-password']);

        // Its account edits other fields without having to change the user name
        $this->signedIn($legacy)->patchJson('/api/account/profile', ['name' => 'Dave'])->assertOk();
        $this->assertSignedInAs($legacy, $this->signIn('dave@legacy', 'dave-password'));
        $this->assertSignedInAs($legacy, $this->signIn('dave@example.test', 'dave-password'));
    }

    public function test_user_names_and_e_mail_addresses_are_unique_in_any_letter_case(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Imposter', 'username' => 'ADA', 'email' => 'ADA.LOVELACE@EXAMPLE.TEST',
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ])->assertUnprocessable()->assertJsonValidationErrors(['username', 'email']);

        $this->signedIn($this->ada)->postJson('/api/users', [
            'name' => 'Imposter', 'username' => 'Ada', 'email' => 'ada.lovelace@example.test', 'password' => 'secret-pass',
        ])->assertUnprocessable()->assertJsonValidationErrors(['username', 'email']);

        $grace = $this->userWithRole('developer', ['username' => 'grace', 'email' => 'grace@example.test']);
        $this->signedIn($this->ada)->patchJson("/api/users/{$grace->id}", ['email' => 'ADA.lovelace@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->signedIn($grace)->patchJson('/api/account/profile', ['username' => 'ADA', 'email' => 'Ada.Lovelace@example.TEST'])
            ->assertUnprocessable()->assertJsonValidationErrors(['username', 'email']);

        // The account's own user name and address in another letter case are its own
        $this->signedIn($this->ada)->patchJson('/api/account/profile', ['username' => 'Ada', 'email' => 'ada.lovelace@example.test'])
            ->assertOk();
        $this->assertSame('Ada', $this->ada->fresh()->username);
        $this->assertSame(2, User::query()->count());
    }

    public function test_a_verified_signup_does_not_take_an_address_of_another_letter_case(): void
    {
        // Signed up while the address was free; an account with it in other capitals came meanwhile
        $pending = PendingSignup::query()->create([
            'email' => 'ada.lovelace@example.test',
            'username' => 'ada-two',
            'name' => 'Ada Two',
            'password' => Hash::make('secret-pass-1'),
            'payload' => [],
            'token_hash' => PendingSignup::hashToken($token = Str::random(64)),
            'expires_at' => now()->addHour(),
            'send_count' => 1,
            'last_sent_at' => now(),
        ]);

        $this->postJson('/api/auth/signup/verify', ['token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertModelMissing($pending);
        $this->assertSame(1, User::query()->count());
    }
}
