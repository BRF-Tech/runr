<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Auth\FrozenAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\AuthProvider\Models\SocialAccount;
use Modules\AuthProvider\Providers\AuthProviderServiceProvider;
use Modules\Tenancy\Http\Middleware\RequireAdmin;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Frozen accounts (auth.frozen_accounts, RUNR_FROZEN_ACCOUNTS; docs/security.md "Shared
 * accounts"): a shared demo login signs in and out and reads its account, but no visitor changes
 * it — no profile, password, two-factor, session, API token, MCP client or linked identity
 * change, no disabling or deleting it, also not through the members' routes on itself. Unlisted
 * users and the administrators are not affected; a frozen administrator still manages the others.
 */
class FrozenAccountTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private const PASSWORD = 'shared demo password';

    private User $owner;

    private User $demo;

    protected function setUp(): void
    {
        parent::setUp();

        // The first user of an install is its Owner; the demo login holds the read-only role
        $this->owner = $this->userWithRole('owner', ['username' => 'operator', 'email' => 'operator@example.test']);
        $this->demo = $this->userWithRole('viewer', ['name' => 'Demo', 'username' => 'demo', 'email' => 'demo@example.test', 'password' => self::PASSWORD]);
    }

    private function freeze(string $list = 'demo@example.test'): void
    {
        config(['auth.frozen_accounts' => $list]);
    }

    /** Requests with a real Sanctum token of the user (sessions are tokens). */
    private function withTokenOf(string $plainTextToken): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($plainTextToken);
    }

    /**
     * Every self-service write of $user's own account, with what it needs: another session, an
     * API token. Disabling and deleting come last (after them the account takes no request).
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function writes(User $user): array
    {
        $otherSession = $user->createToken('api')->accessToken->id;
        $apiToken = $user->createToken('ci')->accessToken->id;

        return [
            'profile' => ['PATCH', '/api/account/profile', ['name' => 'Mallory', 'email' => 'mallory@example.test']],
            'avatar upload' => ['POST', '/api/account/avatar', []],
            'avatar removal' => ['DELETE', '/api/account/avatar', []],
            'password' => ['POST', '/api/account/password', ['current_password' => self::PASSWORD, 'password' => 'taken over', 'password_confirmation' => 'taken over']],
            '2fa setup' => ['POST', '/api/account/2fa/setup', []],
            '2fa confirm' => ['POST', '/api/account/2fa/confirm', ['code' => '123456']],
            '2fa disable' => ['DELETE', '/api/account/2fa', ['password' => self::PASSWORD]],
            '2fa recovery codes' => ['POST', '/api/account/2fa/recovery-codes', []],
            'session revoke' => ['DELETE', "/api/account/sessions/{$otherSession}", []],
            'sessions revoke all' => ['POST', '/api/account/sessions/revoke-all', []],
            'notification preferences' => ['PUT', '/api/account/notification-preferences', ['preferences' => [['type' => 'build', 'channel' => 'email', 'enabled' => false]]]],
            'api token create' => ['POST', '/api/tokens', ['name' => 'visitor']],
            'api token delete' => ['DELETE', "/api/tokens/{$apiToken}", []],
            'mcp client revoke' => ['DELETE', '/api/mcp/clients/someclient', []],
            'oauth consent approve' => ['POST', '/api/oauth2/requests/'.str_repeat('a', 26).'/approve', ['abilities' => ['projects.view'], 'expires_in_days' => 30]],
            'disable' => ['POST', '/api/account/disable', []],
            'delete' => ['DELETE', '/api/account', ['password' => self::PASSWORD]],
        ];
    }

    private function assertFrozen(TestResponse $response, string $label, string $locale = 'en'): void
    {
        $this->assertSame(403, $response->status(), "{$label}: ".$response->getContent());
        $this->assertTrue($response->json('frozen'), $label);
        $this->assertSame(trans('api.account.frozen', [], $locale), $response->json('message'), $label);
    }

    public function test_every_self_service_write_refuses_a_frozen_account(): void
    {
        $this->freeze();
        $token = $this->demo->createToken('api')->plainTextToken;
        $writes = $this->writes($this->demo);
        $tokensBefore = $this->demo->tokens()->pluck('id')->sort()->values()->all();

        foreach ($writes as $label => [$method, $uri, $payload]) {
            $this->assertFrozen($this->withTokenOf($token)->json($method, $uri, $payload), $label);
            // in the caller's language, as the API's other messages
            $this->assertFrozen($this->withTokenOf($token)->json($method, $uri, $payload, ['Accept-Language' => 'tr-TR,tr;q=0.9']), $label, 'tr');
        }

        $this->assertNotSame(trans('api.account.frozen', [], 'en'), trans('api.account.frozen', [], 'tr'));

        // nothing changed
        $demo = $this->demo->fresh();
        $this->assertNotNull($demo);
        $this->assertSame('Demo', $demo->name);
        $this->assertSame('demo@example.test', $demo->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $demo->password));
        $this->assertNull($demo->two_factor_secret);
        $this->assertNull($demo->two_factor_confirmed_at);
        $this->assertFalse((bool) $demo->is_disabled);
        $this->assertSame($tokensBefore, $demo->tokens()->pluck('id')->sort()->values()->all());
        $this->assertSame(0, $demo->notificationPreferences()->count());
    }

    public function test_the_same_writes_work_for_an_unlisted_user(): void
    {
        $this->freeze('someone-else@example.test');

        foreach (array_keys($this->writes($this->owner)) as $label) {
            // a fresh account per write (disabling or deleting one does not end the next), with a
            // two-factor secret set up for the confirm
            $user = $this->userWithRole('viewer', ['password' => self::PASSWORD]);
            $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')])->save();
            $token = $user->createToken('api')->plainTextToken;
            [$method, $uri, $payload] = $this->writes($user)[$label];
            if ($label === 'profile') {
                $payload['email'] = "visitor-{$user->id}@example.test";
            }

            $response = $this->withTokenOf($token)->json($method, $uri, $payload);
            $this->assertNotSame(403, $response->status(), "{$label}: ".$response->getContent());
            $this->assertLessThan(500, $response->status(), "{$label}: ".$response->getContent());
            $response->assertJsonMissingPath('frozen');
        }

        // what the frozen account was refused, done
        $user = $this->userWithRole('viewer', ['password' => self::PASSWORD]);
        $token = $user->createToken('api')->plainTextToken;
        $this->withTokenOf($token)->postJson('/api/account/password', [
            'current_password' => self::PASSWORD, 'password' => 'taken over', 'password_confirmation' => 'taken over',
        ])->assertOk();
        $this->withTokenOf($token)->postJson('/api/account/2fa/setup')->assertOk();
        $this->withTokenOf($token)->postJson('/api/tokens', ['name' => 'cli'])->assertCreated();
        $this->withTokenOf($token)->postJson('/api/account/sessions/revoke-all')->assertOk();
        $this->withTokenOf($token)->postJson('/api/account/disable')->assertOk();
        $this->assertTrue((bool) $user->fresh()->is_disabled);
    }

    public function test_reads_sign_in_and_sign_out_stay_for_a_frozen_account(): void
    {
        $this->freeze();

        $login = $this->postJson('/api/auth/login', ['login' => 'demo', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('user.username', 'demo');
        $token = $login->json('token');

        $this->withTokenOf($token)->getJson('/api/account')->assertOk()->assertJsonPath('frozen', true)->assertJsonPath('username', 'demo');
        $this->withTokenOf($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('frozen', true);
        $this->withTokenOf($token)->getJson('/api/account/sessions')->assertOk();
        $this->withTokenOf($token)->getJson('/api/account/notification-preferences')->assertOk();
        $this->withTokenOf($token)->getJson('/api/tokens')->assertOk();
        $this->withTokenOf($token)->getJson('/api/mcp/clients')->assertOk();
        // the read-only role still reads what it is granted
        $this->withTokenOf($token)->getJson('/api/projects')->assertOk();

        // signing out deletes the caller's own sign-in token
        $this->withTokenOf($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_the_list_is_trimmed_and_case_insensitive(): void
    {
        $this->freeze(' Other@Example.test ,, DEMO@Example.TEST ');
        $this->assertSame(['other@example.test', 'demo@example.test'], FrozenAccounts::emails());
        $this->assertTrue(FrozenAccounts::includes($this->demo));
        $this->assertFalse(FrozenAccounts::includes($this->owner));
        $this->assertFalse(FrozenAccounts::includes(null));

        $this->demo->forceFill(['email' => 'Demo@EXAMPLE.test'])->save();
        $token = $this->demo->createToken('api')->plainTextToken;
        $this->withTokenOf($token)->getJson('/api/account')->assertOk()->assertJsonPath('frozen', true);
        $this->assertFrozen($this->withTokenOf($token)->postJson('/api/account/2fa/setup'), '2fa setup');

        // an address the list only contains a part of is not frozen
        $this->freeze('demo@example.test.evil');
        $this->withTokenOf($token)->getJson('/api/account')->assertOk()->assertJsonPath('frozen', false);
    }

    public function test_no_account_is_frozen_by_default(): void
    {
        $this->assertSame('', config('auth.frozen_accounts'));
        $this->assertSame([], FrozenAccounts::emails());

        $token = $this->demo->createToken('api')->plainTextToken;
        $this->withTokenOf($token)->getJson('/api/account')->assertOk()->assertJsonPath('frozen', false);
        $this->withTokenOf($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('frozen', false);
        $this->withTokenOf($token)->postJson('/api/account/password', [
            'current_password' => self::PASSWORD, 'password' => 'a new password', 'password_confirmation' => 'a new password',
        ])->assertOk()->assertJsonPath('message', __('api.account.password_changed'));
        $this->assertTrue(Hash::check('a new password', $this->demo->fresh()->password));
    }

    public function test_an_administrator_still_manages_a_frozen_account(): void
    {
        $this->freeze();
        $this->demo->forceFill([
            'two_factor_secret' => encrypt('SECRET'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->signedIn($this->owner)->postJson("/api/users/{$this->demo->id}/reset-2fa")
            ->assertOk()->assertJsonPath('message', __('api.user.2fa_reset'));
        $this->assertNull($this->demo->fresh()->two_factor_confirmed_at);

        $this->signedIn($this->owner)->patchJson("/api/users/{$this->demo->id}", ['name' => 'Public demo'])->assertOk();
        $this->assertSame('Public demo', $this->demo->fresh()->name);

        // the operator's own account is not frozen
        $this->signedIn($this->owner)->patchJson('/api/account/profile', ['name' => 'Operator'])->assertOk();
    }

    public function test_a_frozen_administrator_changes_nothing_of_its_own_through_the_members_routes(): void
    {
        $shared = $this->userWithRole('owner', ['name' => 'Shared', 'username' => 'shared', 'email' => 'shared@example.test']);
        $colleague = $this->userWithRole('developer', ['name' => 'Colleague']);
        $team = $this->groupWithRole('Team', null, [$shared, $colleague]);
        foreach ([$shared, $colleague] as $user) {
            $user->forceFill(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()])->save();
        }
        $this->freeze('shared@example.test');

        // on its own account: refused, in the caller's language
        $own = [
            'update' => ['PATCH', "/api/users/{$shared->id}", ['name' => 'Mallory', 'email' => 'mallory@example.test']],
            'delete' => ['DELETE', "/api/users/{$shared->id}", []],
            'reset 2fa' => ['POST', "/api/users/{$shared->id}/reset-2fa", []],
            'role' => ['PUT', "/api/members/{$shared->id}/role", ['role' => 'viewer']],
            'leave group' => ['DELETE', "/api/groups/{$team->id}/members/{$shared->id}", []],
        ];
        foreach ($own as $label => [$method, $uri, $payload]) {
            $this->assertFrozen($this->signedIn($shared)->json($method, $uri, $payload), $label);
            $this->assertFrozen($this->signedIn($shared)->json($method, $uri, $payload, ['Accept-Language' => 'tr']), $label, 'tr');
        }

        $fresh = $shared->fresh();
        $this->assertSame(['Shared', 'shared@example.test'], [$fresh->name, $fresh->email]);
        $this->assertNotNull($fresh->two_factor_confirmed_at);
        $this->assertTrue($this->can($shared, 'members.update'), 'still the owner');
        $this->assertTrue(DB::table('rbac_group_user')->where('group_id', $team->id)->where('user_id', $shared->id)->exists());

        // on another member's: it still manages them
        $this->signedIn($shared)->patchJson("/api/users/{$colleague->id}", ['name' => 'Colleague B'])->assertOk();
        $this->signedIn($shared)->postJson("/api/users/{$colleague->id}/reset-2fa")->assertOk();
        $this->signedIn($shared)->putJson("/api/members/{$colleague->id}/role", ['role' => 'viewer'])->assertOk();
        $this->signedIn($shared)->deleteJson("/api/groups/{$team->id}/members/{$colleague->id}")->assertOk();
        $this->assertSame('Colleague B', $colleague->fresh()->name);
        $this->assertNull($colleague->fresh()->two_factor_confirmed_at);

        // and another administrator still manages the frozen account
        $this->signedIn($this->owner)->patchJson("/api/users/{$shared->id}", ['name' => 'Shared demo'])->assertOk();
        $this->signedIn($this->owner)->postJson("/api/users/{$shared->id}/reset-2fa")->assertOk();
        $this->assertSame('Shared demo', $shared->fresh()->name);
    }

    public function test_the_tenancy_members_routes_refuse_a_frozen_account_on_itself(): void
    {
        $this->registerTenancyRoutes();
        $shared = $this->userWithRole('owner', ['email' => 'shared@example.test']);
        $this->platformAdmin(); // adds users.is_admin
        $shared->forceFill(['is_admin' => true])->save();
        $this->freeze('shared@example.test');

        foreach ([
            'toggle admin' => ['POST', "/api/users/{$shared->id}/toggle-admin"],
            'reset 2fa' => ['POST', "/api/users/{$shared->id}/reset-2fa"],
            'tenant role' => ['PATCH', "/api/tenants/acme/users/{$shared->id}"],
            'tenant remove' => ['DELETE', "/api/tenants/acme/users/{$shared->id}"],
            'tenant reset 2fa' => ['POST', "/api/tenants/acme/users/{$shared->id}/reset-2fa"],
        ] as $label => [$method, $uri]) {
            $this->assertFrozen($this->signedIn($shared)->json($method, $uri, ['role' => 'viewer']), $label);
        }
        $this->assertTrue((bool) $shared->fresh()->is_admin);
    }

    public function test_every_write_on_a_member_carries_the_guard(): void
    {
        $this->registerTenancyRoutes();

        $guarded = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! str_contains($route->uri(), '{user}')
                || array_diff($route->methods(), ['GET', 'HEAD']) === []) {
                continue;
            }
            $key = implode('|', $route->methods()).' '.$route->uri();
            $this->assertContains('account.frozen', $route->gatherMiddleware(), "{$key}: no account.frozen");
            $guarded[] = $key;
        }

        // core, roles & groups and the Tenancy module's routes
        $this->assertGreaterThanOrEqual(9, count($guarded), implode("\n", $guarded));
    }

    /** The Tenancy module's routes, registered as its provider does (the suite runs with it off). */
    private function registerTenancyRoutes(): void
    {
        $this->app['router']->aliasMiddleware('tenancy.admin', RequireAdmin::class);
        Router::middleware(['api', 'auth:sanctum'])->prefix('api')->group(base_path('Modules/Tenancy/routes/api.php'));
        Router::getRoutes()->refreshNameLookups();
        Router::getRoutes()->refreshActionLookups();
    }

    public function test_linked_identities_are_locked_too(): void
    {
        config(['authprovider.enabled' => true]);
        $this->app->register(AuthProviderServiceProvider::class, true);
        Artisan::call('migrate', ['--path' => 'Modules/AuthProvider/Database/Migrations', '--force' => true]);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();

        $linked = SocialAccount::query()->create([
            'user_id' => $this->demo->id, 'provider' => 'github', 'provider_user_id' => '777',
            'email' => 'demo@example.test', 'access_token' => '',
        ]);
        $token = $this->demo->createToken('api')->plainTextToken;

        $this->freeze();
        $this->withTokenOf($token)->getJson('/api/social-accounts')->assertOk();
        $this->assertFrozen($this->withTokenOf($token)->deleteJson("/api/social-accounts/{$linked->id}"), 'unlink');
        $this->assertFrozen($this->withTokenOf($token)->postJson('/api/oauth/link/complete', ['code' => 'staged-code']), 'link');
        $this->assertNotNull(SocialAccount::query()->find($linked->id));

        $this->freeze('');
        $this->withTokenOf($token)->deleteJson("/api/social-accounts/{$linked->id}")->assertOk();
        $this->assertNull(SocialAccount::query()->find($linked->id));
    }
}
