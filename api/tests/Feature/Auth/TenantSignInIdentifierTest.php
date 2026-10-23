<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Tenancy\Http\Controllers\TenancyAuthController;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Password sign-in with the user name or the e-mail address in the multi-tenant edition
 * (docs/security.md, "Password sign-in"; docs/tenancy.md, "Platform users and Tenant
 * users"): the Platform user sign-in and the Tenant user sign-in (tenant_slug) take either
 * identifier in any letter case, membership is checked for the one account the identifier
 * names, a wrong password and an unknown account answer alike, and the rate limit counts every
 * spelling of an account together.
 */
class TenantSignInIdentifierTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private Tenant $acme;

    private User $jane;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();

        // The suite runs with the Tenancy module off: its sign-in controller the way it binds it
        $this->app->bind(AuthController::class, TenancyAuthController::class);

        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        Tenant::create(['name' => 'Globex', 'slug' => 'globex']);

        $this->jane = User::factory()->create(['username' => 'jane', 'email' => 'Jane.Doe@Acme.test', 'password' => 'jane-password']);
        TenantUser::create(['tenant_id' => $this->acme->id, 'user_id' => $this->jane->id, 'role' => 'developer']);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function signIn(string $login, string $password, ?string $tenant = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->postJson('/api/auth/login', array_filter(['login' => $login, 'password' => $password, 'tenant_slug' => $tenant]));
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create(['username' => 'root', 'email' => 'Root@Platform.test', 'password' => 'root-password']);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    public function test_a_tenant_user_signs_in_to_the_tenant_with_the_user_name_or_the_e_mail_address(): void
    {
        foreach (['jane', 'JANE', 'Jane.Doe@Acme.test', 'jane.doe@acme.test', ' JANE.DOE@ACME.TEST '] as $login) {
            $this->signIn($login, 'jane-password', 'acme')
                ->assertOk()
                ->assertJsonPath('user.id', $this->jane->id)
                ->assertJsonPath('active_tenant.slug', 'acme')
                ->assertJsonCount(1, 'tenants');
        }
    }

    public function test_a_platform_user_signs_in_with_the_user_name_or_the_e_mail_address(): void
    {
        $admin = $this->platformAdmin();

        // A platform administrator starts in the central context and sees every tenant
        foreach (['root', 'Root', 'root@platform.test', 'ROOT@PLATFORM.TEST'] as $login) {
            $this->signIn($login, 'root-password')
                ->assertOk()
                ->assertJsonPath('user.id', $admin->id)
                ->assertJsonPath('active_tenant', null)
                ->assertJsonCount(2, 'tenants');
        }

        // Any other account starts in its first tenant
        foreach (['jane', 'jane.doe@acme.test'] as $login) {
            $this->signIn($login, 'jane-password')
                ->assertOk()
                ->assertJsonPath('user.id', $this->jane->id)
                ->assertJsonPath('active_tenant.slug', 'acme');
        }
    }

    public function test_a_wrong_password_and_an_unknown_account_answer_alike(): void
    {
        $answers = [
            $this->signIn('jane', 'not-the-password', 'acme')->assertUnauthorized()->json(),
            $this->signIn('jane.doe@acme.test', 'not-the-password', 'acme')->assertUnauthorized()->json(),
            $this->signIn('nobody', 'not-the-password', 'acme')->assertUnauthorized()->json(),
            $this->signIn('nobody@acme.test', 'not-the-password', 'acme')->assertUnauthorized()->json(),
            $this->signIn('nobody@acme.test', 'not-the-password')->assertUnauthorized()->json(),
        ];

        $this->assertSame([['message' => __('api.auth.login_failed')]], array_values(array_unique($answers, SORT_REGULAR)));
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_the_tenant_sign_in_refuses_a_non_member_whichever_identifier_it_uses(): void
    {
        $outsider = User::factory()->create(['username' => 'otto', 'email' => 'otto@globex.test', 'password' => 'otto-password']);

        foreach (['otto', 'OTTO@globex.test'] as $login) {
            $this->signIn($login, 'otto-password', 'acme')
                ->assertForbidden()
                ->assertJsonPath('message', __('api.tenant.access_denied'));
        }
        // The token of each refused sign-in is gone at once
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $outsider->id)->count());

        // A platform administrator gets into any tenant, by either identifier
        $admin = $this->platformAdmin();
        $this->signIn('ROOT@platform.test', 'root-password', 'globex')
            ->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonPath('active_tenant.slug', 'globex');
    }

    public function test_the_rate_limit_counts_both_identifiers_of_a_tenant_user_together(): void
    {
        config(['ratelimit.auth.per_minute' => 3]);

        $this->signIn('jane', 'wrong-1', 'acme')->assertUnauthorized();
        $this->signIn('jane.doe@acme.test', 'wrong-2', 'acme')->assertUnauthorized();
        // The Platform user page and the tenant's page share the account's budget
        $this->signIn('JANE', 'wrong-3')->assertUnauthorized();

        $this->signIn('Jane.Doe@Acme.test', 'jane-password', 'acme')->assertStatus(429)->assertHeader('Retry-After');

        // Another account has its own budget
        $admin = $this->platformAdmin();
        $this->signIn('root', 'root-password')->assertOk()->assertJsonPath('user.id', $admin->id);
    }
}
