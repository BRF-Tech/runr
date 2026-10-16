<?php

namespace Tests\Feature;

use App\Events\PlatformEvent;
use App\Models\User;
use App\Support\Auth\LoginTokens;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Sign-in sessions and API tokens (docs/security.md, "Sign-in sessions and API tokens"): a token
 * that was signed out, revoked or deleted answers 401 for good (the session tracking after the
 * request never writes it back), a sign-in session ends after its lifetime (also one signed in
 * before sessions had an expiry), API tokens keep their own expiry, and the daily prune removes
 * what ended.
 */
class SignInSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // First user of the install: Owner (may create API tokens)
        $this->user = User::factory()->create(['username' => 'ada', 'email' => 'ada@example.test', 'password' => 'correct horse']);
    }

    /** POST /api/auth/login as the console and `runr login --with-password` do: the session token. */
    private function signIn(): string
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->postJson('/api/auth/login', ['login' => 'ada', 'password' => 'correct horse'])->assertOk()->json('token');
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function me(string $token): TestResponse
    {
        return $this->as($token)->getJson('/api/auth/me');
    }

    /** @return array{plain: string, id: int} */
    private function apiToken(string $session, string $name): array
    {
        $created = $this->as($session)->postJson('/api/tokens', ['name' => $name])->assertCreated();

        return ['plain' => $created->json('plain_token'), 'id' => (int) $created->json('token.id')];
    }

    /** A token of the user created $daysOld days ago. */
    private function tokenAged(string $name, int $daysOld, mixed $expiresAt = null): string
    {
        $new = $this->user->createToken($name, ['*'], $expiresAt);
        $new->accessToken->forceFill(['created_at' => now()->subDays($daysOld)])->save();

        return $new->plainTextToken;
    }

    private function idOf(string $plain): int
    {
        return (int) PersonalAccessToken::findToken($plain)->getKey();
    }

    // ── Revocation ──────────────────────────────────────────────────────────

    public function test_signing_out_ends_the_session_for_good(): void
    {
        $token = $this->signIn();
        $id = $this->idOf($token);
        $this->me($token)->assertOk()->assertJsonPath('username', 'ada');

        $this->as($token)->postJson('/api/auth/logout')->assertOk()->assertJsonPath('message', __('api.auth.logged_out'));

        // The session tracking after the sign-out request must not write the deleted token back
        $this->assertNull(PersonalAccessToken::find($id));
        $this->me($token)->assertUnauthorized();
        $this->me($token)->assertUnauthorized();
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_the_session_details_are_written_only_when_they_change(): void
    {
        $token = $this->signIn();
        $writes = 0;
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            // Sanctum's own last_used_at update is not the session tracking
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'personal_access_tokens') && ! str_contains($query->sql, 'last_used_at')) {
                $writes++;
            }
        });

        $this->me($token)->assertOk();
        $this->assertSame(0, $writes, 'same address and user agent as at the sign-in: nothing to write');

        $this->as($token)->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; Mobile)')->getJson('/api/auth/me')->assertOk();
        $this->assertSame(1, $writes);
        $row = PersonalAccessToken::findToken($token);
        $this->assertSame('Mozilla/5.0 (iPhone; Mobile)', $row->user_agent);
        $this->assertSame('127.0.0.1', $row->ip_address);
        $this->assertSame('Desktop', $row->device_name, 'the device named at the sign-in is kept');
    }

    public function test_revoked_sessions_stay_revoked(): void
    {
        Event::fake([PlatformEvent::class]);
        $current = $this->signIn();
        $phone = $this->signIn();
        $tablet = $this->signIn();

        $this->as($current)->deleteJson('/api/account/sessions/'.$this->idOf($phone))->assertOk();
        $this->me($phone)->assertUnauthorized();
        $this->me($tablet)->assertOk();

        $this->as($current)->postJson('/api/account/sessions/revoke-all')->assertOk();
        $this->me($tablet)->assertUnauthorized();
        $this->me($phone)->assertUnauthorized();
        $this->me($current)->assertOk();
    }

    public function test_a_deleted_api_token_stays_deleted_also_when_it_deleted_itself(): void
    {
        $session = $this->signIn();
        $ci = $this->apiToken($session, 'ci');
        $cli = $this->apiToken($session, 'runr-cli@laptop');

        $this->as($session)->deleteJson("/api/tokens/{$ci['id']}")->assertOk();
        $this->me($ci['plain'])->assertUnauthorized();

        // `runr logout`: the CLI's token revokes itself
        $this->as($cli['plain'])->deleteJson("/api/tokens/{$cli['id']}")->assertOk();
        $this->assertNull(PersonalAccessToken::find($cli['id']));
        $this->me($cli['plain'])->assertUnauthorized();
        $this->me($session)->assertOk();
    }

    public function test_a_cli_password_login_keeps_only_its_api_token(): void
    {
        // runr login --with-password: sign in, create the machine's API token, end the session
        $session = $this->signIn();
        $cli = $this->apiToken($session, 'runr-cli@laptop');
        $this->as($session)->postJson('/api/auth/logout')->assertOk();

        $this->me($session)->assertUnauthorized();
        $this->me($cli['plain'])->assertOk();
        $this->assertSame(['runr-cli@laptop'], $this->user->tokens()->pluck('name')->all());
    }

    public function test_changing_the_password_ends_the_other_sign_in_sessions_only(): void
    {
        Event::fake([PlatformEvent::class]);
        $current = $this->signIn();
        $other = $this->signIn();
        $invited = LoginTokens::issue($this->user, 'invitation-accept')->plainTextToken;
        $ci = $this->user->createToken('ci')->plainTextToken;
        $ended = [$this->idOf($other), $this->idOf($invited)];

        $this->as($current)->postJson('/api/account/password', [
            'current_password' => 'correct horse', 'password' => 'battery staple', 'password_confirmation' => 'battery staple',
        ])->assertOk()->assertJsonPath('message', __('api.account.password_changed'));

        $this->me($other)->assertUnauthorized();
        $this->me($invited)->assertUnauthorized();
        $this->me($current)->assertOk();
        $this->me($ci)->assertOk();
        Event::assertDispatched(PlatformEvent::class, function (PlatformEvent $e) use ($ended) {
            $ids = $e->payload['token_ids'] ?? [];
            sort($ids);

            return $e->topic === 'session.revoked' && $ids === $ended;
        });

        // Changed with an API token: every sign-in session ends, the API token stays
        $again = LoginTokens::issue($this->user)->plainTextToken;
        $this->as($ci)->postJson('/api/account/password', [
            'current_password' => 'battery staple', 'password' => 'correct horse', 'password_confirmation' => 'correct horse',
        ])->assertOk();
        $this->me($again)->assertUnauthorized();
        $this->me($current)->assertUnauthorized();
        $this->me($ci)->assertOk();
    }

    public function test_a_disabled_or_deleted_account_keeps_no_working_token(): void
    {
        $current = $this->signIn();
        $other = $this->signIn();

        $this->as($current)->postJson('/api/account/disable')->assertOk();
        $this->me($other)->assertUnauthorized();
        $this->me($current)->assertForbidden()->assertJsonPath('message', __('api.account.account_disabled'));

        $grace = User::factory()->create(['username' => 'grace', 'password' => 'grace-password']);
        $token = LoginTokens::issue($grace)->plainTextToken;
        $this->as($token)->deleteJson('/api/account', ['password' => 'grace-password'])->assertOk();
        $this->me($token)->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $grace->id)->count());
    }

    // ── Lifetime ────────────────────────────────────────────────────────────

    public function test_a_sign_in_session_lasts_the_configured_lifetime(): void
    {
        $this->freezeSecond();

        $token = $this->signIn();
        $this->assertSame(now()->addDays(30)->toDateTimeString(), PersonalAccessToken::findToken($token)->expires_at?->toDateTimeString());

        config(['auth.login_token_ttl_days' => 7]);
        $this->assertSame(now()->addDays(7)->toDateTimeString(), PersonalAccessToken::findToken($this->signIn())->expires_at?->toDateTimeString());

        config(['auth.login_token_ttl_days' => 0]);
        $this->assertNull(PersonalAccessToken::findToken($this->signIn())->expires_at, '0: until signed out');

        config(['auth.login_token_ttl_days' => 30]);
        $this->travel(29)->days();
        $this->me($token)->assertOk();
        $this->travel(2)->days();
        $this->me($token)->assertUnauthorized();
    }

    public function test_sign_in_sessions_from_before_the_lifetime_end_by_their_age(): void
    {
        $old = $this->tokenAged('api', 31);
        $young = $this->tokenAged('api', 29);
        $oldInvitation = $this->tokenAged('invitation-accept', 31);
        $thirtyDays = $this->tokenAged('api', 8, now()->addDays(22));
        $oldApiToken = $this->tokenAged('ci', 400);
        $apiTokenWithExpiry = $this->tokenAged('deploy', 400, now()->addDay());
        $expiredApiToken = $this->tokenAged('old', 2, now()->subMinute());

        $this->me($old)->assertUnauthorized();
        $this->me($oldInvitation)->assertUnauthorized();
        $this->me($young)->assertOk();
        $this->me($thirtyDays)->assertOk();
        $this->me($oldApiToken)->assertOk();
        $this->me($apiTokenWithExpiry)->assertOk();
        $this->me($expiredApiToken)->assertUnauthorized();

        // A lowered lifetime ends the sessions it is now past, whatever their expires_at says
        config(['auth.login_token_ttl_days' => 7]);
        $this->me($thirtyDays)->assertUnauthorized();
        $this->me($oldApiToken)->assertOk();

        config(['auth.login_token_ttl_days' => 0]);
        $this->me($old)->assertOk();
    }

    public function test_the_lifetime_rule_outside_sanctums_guard(): void
    {
        $token = fn (string $plain) => PersonalAccessToken::findToken($plain);

        $this->assertTrue(LoginTokens::expired($token($this->tokenAged('api', 31))));
        $this->assertFalse(LoginTokens::expired($token($this->tokenAged('api', 1))));
        $this->assertFalse(LoginTokens::expired($token($this->tokenAged('ci', 400))));
        $this->assertTrue(LoginTokens::expired($token($this->tokenAged('ci', 1, now()->subMinute()))));

        config(['sanctum.expiration' => 60]);
        $this->assertTrue(LoginTokens::expired($token($this->tokenAged('ci', 1))));
    }

    // ── API tokens ──────────────────────────────────────────────────────────

    public function test_api_tokens_cannot_take_the_names_of_sign_in_sessions(): void
    {
        $session = $this->signIn();
        LoginTokens::issue($this->user, 'invitation-accept');

        foreach (LoginTokens::NAMES as $name) {
            $this->as($session)->postJson('/api/tokens', ['name' => $name])->assertStatus(422)->assertJsonValidationErrors('name');
        }
        $this->as($session)->postJson('/api/tokens', ['name' => 'ci'])->assertCreated()->assertJsonPath('token.expires_at', null);

        $listed = $this->as($session)->getJson('/api/tokens')->assertOk()->json('tokens');
        $this->assertSame(['ci'], array_column($listed, 'name'), 'sign-in sessions are listed with the sessions');
    }

    // ── Prune ───────────────────────────────────────────────────────────────

    public function test_ended_sessions_and_long_expired_api_tokens_are_pruned_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $e) => $e->description === 'token-prune');
        $this->assertNotNull($event, 'scheduled task token-prune');
        $this->assertSame('15 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('tokens:prune', $event->command);

        $keep = [
            $this->idOf($this->tokenAged('api', 1, now()->addDays(29))),
            $this->idOf($this->tokenAged('ci', 400)),
            $this->idOf($this->tokenAged('deploy', 40, now()->subDays(5))),
        ];
        $this->tokenAged('api', 31);
        $this->tokenAged('invitation-accept', 2, now()->subMinute());
        $this->tokenAged('old', 90, now()->subDays(31));
        $this->tokenAged('Assistant (MCP)', 60, now()->subDays(40));

        $this->artisan('tokens:prune')
            ->expectsOutputToContain('Removed 2 sign-in session(s) and 2 expired API token(s).')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing($keep, PersonalAccessToken::query()->pluck('id')->map(fn ($id) => (int) $id)->all());
    }
}
