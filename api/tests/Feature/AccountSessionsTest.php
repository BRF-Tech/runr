<?php

namespace Tests\Feature;

use App\Events\PlatformEvent;
use App\Models\User;
use App\Support\Auth\LoginTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Sign-in sessions and API tokens are rows of one table (docs/security.md, "Sign-in sessions and
 * API tokens"); the account's session routes (Account settings → Active Sessions) cover the
 * sign-in sessions only, the API token routes the API tokens only — revoking the other sessions
 * no longer ends the user's CI, CLI and MCP client tokens. Every path that revokes tokens of a
 * user is checked for what it ends: sign-out, revoking a session or all others, a password
 * change, two-factor changes, disabling and deleting the account, and a frozen account's refusal.
 */
class AccountSessionsTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private const PASSWORD = 'correct horse';

    private const ALL = ['current', 'other', 'invited', 'ci', 'cli', 'mcp'];

    private const SESSIONS = ['current', 'other', 'invited'];

    private const API_TOKENS = ['ci', 'cli', 'mcp'];

    private User $owner;

    private User $ada;

    /** @var array<string, string> plain text token by label */
    private array $plain = [];

    /** @var array<string, int> token id by label */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The first user of an install is its Owner; Ada is a developer (may create API tokens)
        $this->owner = $this->userWithRole('owner', ['username' => 'owner', 'email' => 'owner@example.test']);
        $this->ada = $this->userWithRole('developer', ['username' => 'ada', 'email' => 'ada@example.test', 'password' => self::PASSWORD]);
    }

    private function as(string $plainTextToken): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($plainTextToken);
    }

    private function keep(string $label, string $plainTextToken): void
    {
        $this->plain[$label] = $plainTextToken;
        $this->ids[$label] = (int) PersonalAccessToken::findToken($plainTextToken)->getKey();
    }

    /**
     * Ada's tokens: the console session of this request (`current`, a password sign-in), another
     * browser (`other`), the session of an accepted invitation (`invited`), an API token she
     * created (`ci`), the token of `runr login --with-password` (`cli`) and an MCP client's (`mcp`).
     */
    private function tokens(): void
    {
        $this->app['auth']->forgetGuards();
        $this->keep('current', $this->postJson('/api/auth/login', ['login' => 'ada', 'password' => self::PASSWORD])->assertOk()->json('token'));
        $this->keep('other', LoginTokens::issue($this->ada)->plainTextToken);
        $this->keep('invited', LoginTokens::issue($this->ada, 'invitation-accept')->plainTextToken);
        $this->keep('ci', $this->as($this->plain['current'])->postJson('/api/tokens', ['name' => 'ci'])->assertCreated()->json('plain_token'));
        $this->keep('cli', $this->ada->createToken('runr-cli@laptop', ['*'], now()->addDays(90))->plainTextToken);
        $mcp = $this->ada->createToken('Assistant (MCP)', ['projects.view'], now()->addDays(30));
        $mcp->accessToken->forceFill(['oauth_client_id' => 'assistant', 'oauth_resource' => url('/mcp')])->save();
        $this->keep('mcp', $mcp->plainTextToken);
    }

    /** @return list<string> the labels of Ada's tokens that still exist, in the order of ALL */
    private function remaining(): array
    {
        $left = PersonalAccessToken::query()->whereKey(array_values($this->ids))->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_filter(self::ALL, fn (string $label) => in_array($this->ids[$label], $left, true)));
    }

    /** @param list<string> $labels */
    private function idsOf(array $labels): array
    {
        $ids = array_map(fn (string $label) => $this->ids[$label], $labels);
        sort($ids);

        return $ids;
    }

    private function assertNoSessionRevokedEvent(): void
    {
        Event::assertNotDispatched(PlatformEvent::class, fn (PlatformEvent $e) => $e->topic === 'session.revoked');
    }

    private function confirmTwoFactor(): void
    {
        $this->ada->forceFill([
            'two_factor_secret' => encrypt((new Google2FA)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    // ── The session routes: sign-in sessions only ──────────────────────────

    public function test_active_sessions_list_the_sign_in_sessions_and_no_api_token(): void
    {
        $this->tokens();

        $sessions = $this->as($this->plain['current'])->getJson('/api/account/sessions')->assertOk()->json('sessions');
        $this->assertEqualsCanonicalizing($this->idsOf(self::SESSIONS), array_column($sessions, 'id'));
        $this->assertSame([$this->ids['current']], array_column(array_filter($sessions, fn ($s) => $s['is_current']), 'id'));

        // The API tokens are the API token routes' (the current session is not one of them)
        $tokens = $this->getJson('/api/tokens')->assertOk()->json('tokens');
        $this->assertEqualsCanonicalizing($this->idsOf(self::API_TOKENS), array_column($tokens, 'id'));

        // Asked with an API token: the same sessions, none of them the current one
        $sessions = $this->as($this->plain['ci'])->getJson('/api/account/sessions')->assertOk()->json('sessions');
        $this->assertEqualsCanonicalizing($this->idsOf(self::SESSIONS), array_column($sessions, 'id'));
        $this->assertSame([], array_filter(array_column($sessions, 'is_current')));
    }

    public function test_a_request_without_a_stored_token_lists_the_sessions_none_current(): void
    {
        $this->tokens();

        $sessions = $this->signedIn($this->ada)->getJson('/api/account/sessions')->assertOk()->json('sessions');

        $this->assertEqualsCanonicalizing($this->idsOf(self::SESSIONS), array_column($sessions, 'id'));
        $this->assertSame([], array_filter(array_column($sessions, 'is_current')));
    }

    public function test_active_sessions_leave_out_the_sessions_that_ended(): void
    {
        $this->tokens();
        $session = function (int $daysOld, mixed $expiresAt): int {
            $token = $this->ada->createToken('api', ['*'], $expiresAt)->accessToken;
            $token->forceFill(['created_at' => now()->subDays($daysOld)])->save();

            return (int) $token->getKey();
        };
        $pastExpiry = $session(2, now()->subMinute());
        $outlived = $session(31, null);                    // older than the 30-day session lifetime
        $live = $session(29, now()->addDay());

        $listed = fn () => array_column($this->as($this->plain['current'])->getJson('/api/account/sessions')->assertOk()->json('sessions'), 'id');

        $this->assertEqualsCanonicalizing([...$this->idsOf(self::SESSIONS), $live], $listed());
        $this->assertNotContains($pastExpiry, $listed());
        $this->assertNotContains($outlived, $listed());

        // The guard's other rule: sanctum.expiration (minutes after the sign-in)
        config(['sanctum.expiration' => 60 * 24]);
        $this->assertEqualsCanonicalizing($this->idsOf(self::SESSIONS), $listed());

        // A session lifetime of 0 keeps sessions without an expiry until they are revoked
        config(['sanctum.expiration' => null, 'auth.login_token_ttl_days' => 0]);
        $this->assertEqualsCanonicalizing([...$this->idsOf(self::SESSIONS), $live, $outlived], $listed());
    }

    public function test_revoking_a_session_by_the_id_of_an_api_token_is_not_found(): void
    {
        $this->tokens();
        Event::fake([PlatformEvent::class]);

        foreach (self::API_TOKENS as $label) {
            $this->as($this->plain['current'])->deleteJson("/api/account/sessions/{$this->ids[$label]}")->assertNotFound();
        }
        // Its own id, asked with the API token: not a session either (404, not "the current session")
        $this->as($this->plain['ci'])->deleteJson("/api/account/sessions/{$this->ids['ci']}")->assertNotFound();

        $this->assertSame(self::ALL, $this->remaining());
        $this->assertNoSessionRevokedEvent();
        $this->as($this->plain['ci'])->getJson('/api/auth/me')->assertOk();
    }

    public function test_the_api_token_routes_never_delete_a_sign_in_session(): void
    {
        $this->tokens();

        foreach (self::SESSIONS as $label) {
            $this->as($this->plain['ci'])->deleteJson("/api/tokens/{$this->ids[$label]}")
                ->assertNotFound()->assertJsonPath('message', __('api.token.not_found'));
        }

        $this->assertSame(self::ALL, $this->remaining());
        $this->as($this->plain['other'])->getJson('/api/auth/me')->assertOk();

        // An API token still goes
        $this->as($this->plain['current'])->deleteJson("/api/tokens/{$this->ids['cli']}")->assertOk();
        $this->assertSame(['current', 'other', 'invited', 'ci', 'mcp'], $this->remaining());
    }

    public function test_signing_out_a_cookie_session_ends_it_and_no_token(): void
    {
        $this->tokens();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        // The console's cookie session (Sanctum hands the request a TransientToken, no stored token)
        Auth::guard('web')->login($this->ada);
        $sessionKey = Auth::guard('web')->getName();
        $this->assertTrue(session()->has($sessionKey));

        $this->postJson('/api/auth/logout')->assertOk()->assertJsonPath('message', __('api.auth.logged_out'));

        $this->assertFalse(Auth::guard('web')->check());
        $this->assertFalse(session()->has($sessionKey), 'the web session no longer signs anyone in');
        $this->assertSame(self::ALL, $this->remaining(), 'no stored token ends');
        // Fresh guards read the session alone: nobody is signed in with it any more
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    // ── Every path that revokes tokens ──────────────────────────────────────

    /**
     * What each path leaves of Ada's tokens, and the sessions its session.revoked event names
     * (null: no event).
     *
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>|null}>
     */
    public static function revokePaths(): array
    {
        return [
            'sign-out' => ['sign-out', ['other', 'invited', 'ci', 'cli', 'mcp'], null],
            'sign-out of an API token (it ends itself)' => ['sign-out with ci', ['current', 'other', 'invited', 'cli', 'mcp'], null],
            'revoke one session' => ['revoke other', ['current', 'invited', 'ci', 'cli', 'mcp'], ['other']],
            'revoke all other sessions' => ['revoke all', ['current', 'ci', 'cli', 'mcp'], ['other', 'invited']],
            'revoke all sessions with an API token' => ['revoke all with ci', self::API_TOKENS, self::SESSIONS],
            'password change' => ['password', ['current', 'ci', 'cli', 'mcp'], ['other', 'invited']],
            'password change with an API token' => ['password with ci', self::API_TOKENS, self::SESSIONS],
            'two-factor turned on' => ['2fa on', self::ALL, null],
            'two-factor turned off' => ['2fa off', self::ALL, null],
            'two-factor reset by an administrator' => ['2fa reset', self::ALL, null],
            // Every token it ends is named, so the browsers of the ended sessions sign out at once
            'account disabled' => ['disable', ['current'], ['other', 'invited', 'ci', 'cli', 'mcp']],
            'account disabled with an API token' => ['disable with ci', ['ci'], ['current', 'other', 'invited', 'cli', 'mcp']],
            'account deleted' => ['delete', [], null],
            'frozen account: revoke one session' => ['frozen revoke other', self::ALL, null],
            'frozen account: revoke all other sessions' => ['frozen revoke all', self::ALL, null],
            'frozen account: password change' => ['frozen password', self::ALL, null],
            'frozen account: disable' => ['frozen disable', self::ALL, null],
            'frozen account: sign-out stays' => ['frozen sign-out', ['other', 'invited', 'ci', 'cli', 'mcp'], null],
        ];
    }

    /**
     * @param  list<string>  $remaining
     * @param  list<string>|null  $revokedEvent
     */
    #[DataProvider('revokePaths')]
    public function test_each_path_ends_the_tokens_it_should(string $path, array $remaining, ?array $revokedEvent): void
    {
        $this->tokens();
        Event::fake([PlatformEvent::class]);

        $response = $this->revoke($path);

        $this->assertSame(str_starts_with($path, 'frozen ') && $path !== 'frozen sign-out' ? 403 : 200, $response->status(), $response->getContent());
        $this->assertSame($remaining, $this->remaining());

        if ($revokedEvent === null) {
            $this->assertNoSessionRevokedEvent();
        } else {
            $expected = $this->idsOf($revokedEvent);
            Event::assertDispatched(PlatformEvent::class, function (PlatformEvent $e) use ($expected) {
                $ids = $e->payload['token_ids'] ?? [];
                sort($ids);

                return $e->topic === 'session.revoked' && $ids === $expected;
            });
        }
    }

    private function revoke(string $path): TestResponse
    {
        $password = ['current_password' => self::PASSWORD, 'password' => 'battery staple', 'password_confirmation' => 'battery staple'];

        if (str_starts_with($path, 'frozen ')) {
            config(['auth.frozen_accounts' => 'ada@example.test']);
            $path = substr($path, strlen('frozen '));
        }

        switch ($path) {
            case 'sign-out':
                return $this->as($this->plain['current'])->postJson('/api/auth/logout');
            case 'sign-out with ci':
                return $this->as($this->plain['ci'])->postJson('/api/auth/logout');
            case 'revoke other':
                return $this->as($this->plain['current'])->deleteJson("/api/account/sessions/{$this->ids['other']}");
            case 'revoke all':
                return $this->as($this->plain['current'])->postJson('/api/account/sessions/revoke-all');
            case 'revoke all with ci':
                return $this->as($this->plain['ci'])->postJson('/api/account/sessions/revoke-all');
            case 'password':
                return $this->as($this->plain['current'])->postJson('/api/account/password', $password);
            case 'password with ci':
                return $this->as($this->plain['ci'])->postJson('/api/account/password', $password);
            case '2fa on':
                $secret = $this->as($this->plain['current'])->postJson('/api/account/2fa/setup')->assertOk()->json('secret');

                return $this->postJson('/api/account/2fa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)]);
            case '2fa off':
                $this->confirmTwoFactor();

                return $this->as($this->plain['current'])->deleteJson('/api/account/2fa', ['password' => self::PASSWORD]);
            case '2fa reset':
                $this->confirmTwoFactor();

                return $this->as(LoginTokens::issue($this->owner)->plainTextToken)->postJson("/api/users/{$this->ada->id}/reset-2fa");
            case 'disable':
                return $this->as($this->plain['current'])->postJson('/api/account/disable');
            case 'disable with ci':
                return $this->as($this->plain['ci'])->postJson('/api/account/disable');
            case 'delete':
                return $this->as($this->plain['current'])->deleteJson('/api/account', ['password' => self::PASSWORD]);
        }

        $this->fail("unknown path {$path}");
    }
}
