<?php

namespace Tests\Feature;

use App\Support\HorizonAccess;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * The Horizon dashboard: the console signs in with API tokens, which a browser does not send to
 * /horizon, so administrators — platform administrators, single-tenant Owners — open it with a
 * single-use link (App\Support\HorizonAccess) that signs the browser's session in. Any other
 * request is refused, including every request of an authenticated non-administrator.
 */
class HorizonAccessTest extends TestCase
{
    use ActsWithRoles;
    use RefreshDatabase;

    private function access(): HorizonAccess
    {
        return app(HorizonAccess::class);
    }

    /** A request to /horizon; without $session from a new browser (a session of its own) */
    private function browserRequest(?string $token = null, ?Session $session = null): Request
    {
        $request = Request::create('/horizon'.($token !== null ? '?'.http_build_query(['access' => $token]) : ''));
        if ($session === null) {
            $session = new Store('runr_session', new ArraySessionHandler(120));
            $session->start();
        }
        $request->setLaravelSession($session);

        return $request;
    }

    private function tokenOf(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['access'];
    }

    public function test_an_owner_signs_in_once_with_the_link_and_the_session_keeps_the_access(): void
    {
        $owner = $this->userWithRole('owner');
        $link = $this->access()->issue($owner);
        $this->assertStringStartsWith(url('horizon').'?access=', $link['url']);

        $first = $this->browserRequest($this->tokenOf($link['url']));
        $this->assertTrue($this->access()->allows($first));

        // the same browser (session) without the token
        $this->assertTrue($this->access()->allows($this->browserRequest(session: $first->session())));
    }

    public function test_the_link_works_once(): void
    {
        $owner = $this->userWithRole('owner');
        $token = $this->tokenOf($this->access()->issue($owner)['url']);

        $this->assertTrue($this->access()->allows($this->browserRequest($token)));
        $this->assertFalse($this->access()->allows($this->browserRequest($token)), 'a second browser with the same link');
    }

    public function test_the_link_expires(): void
    {
        $owner = $this->userWithRole('owner');
        $token = $this->tokenOf($this->access()->issue($owner, 2)['url']);

        $this->travel(3)->minutes();

        $this->assertFalse($this->access()->allows($this->browserRequest($token)));
    }

    public function test_no_link_no_access(): void
    {
        $this->userWithRole('owner');

        $this->assertFalse($this->access()->allows($this->browserRequest()));
        $this->assertFalse($this->access()->allows($this->browserRequest('not-a-token')));
        $this->get('/horizon')->assertForbidden();
        $this->get('/horizon/api/stats')->assertForbidden();
    }

    public function test_only_administrators_get_a_link(): void
    {
        $this->userWithRole('owner');
        $developer = $this->userWithRole('developer');
        $admin = $this->userWithRole('admin');

        foreach ([$developer, $admin] as $user) {
            $this->assertFalse($this->access()->isAdministrator($user), $user->username);
            $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->access()->issue($developer);
    }

    public function test_platform_administrators_qualify_and_tenant_owners_do_not(): void
    {
        $platformAdmin = $this->platformAdmin();
        $this->assertTrue($this->access()->isAdministrator($platformAdmin));
        $this->assertTrue(Gate::forUser($platformAdmin)->allows('viewHorizon'));

        config(['tenancy.enabled' => true]);
        $owner = $this->userWithRole('owner');
        $this->assertFalse($this->access()->isAdministrator($owner), 'an Owner role is the tenant\'s in a multi-tenant install');
        $this->assertTrue($this->access()->isAdministrator($platformAdmin));
    }

    public function test_losing_the_role_or_the_account_ends_the_session_access(): void
    {
        $owner = $this->userWithRole('owner');
        $first = $this->browserRequest($this->tokenOf($this->access()->issue($owner)['url']));
        $this->assertTrue($this->access()->allows($first));

        $owner->forceFill(['is_disabled' => true])->save();

        $this->assertFalse($this->access()->allows($this->browserRequest(session: $first->session())));
        $this->assertNull($first->session()->get(HorizonAccess::SESSION_KEY));
    }

    public function test_a_disabled_account_gets_no_access_with_a_link(): void
    {
        $owner = $this->userWithRole('owner');
        $token = $this->tokenOf($this->access()->issue($owner)['url']);
        $owner->forceFill(['is_disabled' => true])->save();

        $this->assertFalse($this->access()->allows($this->browserRequest($token)));
    }

    public function test_the_api_issues_links_to_administrators_with_an_unscoped_token_only(): void
    {
        $owner = $this->userWithRole('owner');
        $developer = $this->userWithRole('developer');

        $response = $this->signedIn($owner)->postJson('/api/horizon/link')->assertOk();
        $this->assertStringStartsWith(url('horizon').'?access=', $response->json('url'));
        $this->assertNotEmpty($response->json('expires_at'));
        $this->assertTrue($this->access()->allows($this->browserRequest($this->tokenOf($response->json('url')))));

        $this->signedIn($developer)->postJson('/api/horizon/link')->assertForbidden();

        $scoped = $owner->createToken('ci', ['projects.view'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$scoped)->postJson('/api/horizon/link')->assertForbidden();
    }

    public function test_the_command_prints_a_link_for_the_oldest_administrator_or_the_named_one(): void
    {
        $owner = $this->userWithRole('owner');
        $developer = $this->userWithRole('developer');

        $this->assertSame(0, Artisan::call('runr:horizon-link'));
        $output = Artisan::output();
        $this->assertStringContainsString(url('horizon').'?access=', $output);
        $this->assertStringContainsString("for {$owner->username}", $output);

        $this->assertSame(1, Artisan::call('runr:horizon-link', ['--user' => $developer->username]));
        $this->assertStringNotContainsString('?access=', Artisan::output());
    }

    /** --user names an account the way the sign-in does (App\Support\Auth\LoginIdentifier). */
    public function test_the_command_takes_the_user_name_or_the_e_mail_address_in_any_letter_case(): void
    {
        $owner = $this->userWithRole('owner', ['username' => 'olga', 'email' => 'Olga@Example.test']);
        $this->userWithRole('developer', ['username' => 'dev', 'email' => 'dev@example.test']);

        foreach (['olga', 'OLGA', 'olga@example.test', 'OLGA@EXAMPLE.TEST'] as $name) {
            $this->assertSame(0, Artisan::call('runr:horizon-link', ['--user' => $name]), $name);
            $this->assertStringContainsString("for {$owner->username}", Artisan::output());
        }

        $this->assertSame(1, Artisan::call('runr:horizon-link', ['--user' => 'DEV@example.test']));
        $this->assertStringNotContainsString('?access=', Artisan::output());
    }

    public function test_local_development_lets_everyone_in(): void
    {
        $this->app['env'] = 'local';

        $this->assertTrue($this->access()->allows($this->browserRequest()));
    }
}
