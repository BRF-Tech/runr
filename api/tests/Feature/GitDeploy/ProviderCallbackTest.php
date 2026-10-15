<?php

namespace Tests\Feature\GitDeploy;

use App\Models\Git\GitInstallation;
use App\Models\Git\GitProvider;
use App\Models\Project;
use App\Models\User;
use App\Services\GitDeploy\OAuthState;
use App\Services\GitDeploy\RepoConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\GitDeployFixtures;
use Tests\TestCase;

/**
 * Browser round trips: the GitHub App installation callback links an installation only when
 * GitHub confirms the user's access; the GitLab OAuth callback hands a short-lived token
 * reference back to the Git tab.
 */
class ProviderCallbackTest extends TestCase
{
    use ActsWithRoles, GitDeployFixtures, RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGitDeploy();
        $this->owner = $this->userWithRole('owner');
        $this->project = $this->faasProject($this->demoRegion());
    }

    protected function tearDown(): void
    {
        $this->tearDownGitDeploy();
        parent::tearDown();
    }

    private function state(GitProvider $provider): string
    {
        return OAuthState::issue((string) $this->owner->id, null, $this->project->id, $provider->id);
    }

    public function test_a_confirmed_installation_is_linked(): void
    {
        $github = $this->provider(GitProvider::GITHUB);
        $this->httpResponses = [
            'POST /login/oauth/access_token' => [200, ['access_token' => 'ghu_user']],
            'GET /user/installations' => [200, ['installations' => [['id' => 777, 'account' => ['login' => 'acme', 'type' => 'Organization']]]]],
        ];

        $this->get('/api/git/github/setup?'.http_build_query(['installation_id' => '777', 'setup_action' => 'install', 'code' => 'oauth-code', 'state' => $this->state($github)]))
            ->assertRedirect("https://console.test/projects/{$this->project->id}?git_installation=777#git");

        $installation = GitInstallation::firstOrFail();
        $this->assertSame('777', $installation->installation_id);
        $this->assertSame('acme', $installation->account_login);
        $this->assertNull($installation->tenant_id);

        $exchange = $this->calls('POST', '/login/oauth/access_token');
        $this->assertNotEmpty($exchange);
    }

    public function test_an_installation_the_user_cannot_access_is_not_linked(): void
    {
        $github = $this->provider(GitProvider::GITHUB);
        $this->httpResponses = [
            'POST /login/oauth/access_token' => [200, ['access_token' => 'ghu_user']],
            'GET /user/installations' => [200, ['installations' => [['id' => 1, 'account' => ['login' => 'someone']]]]],
        ];

        $this->get('/api/git/github/setup?'.http_build_query(['installation_id' => '777', 'code' => 'c', 'state' => $this->state($github)]))
            ->assertRedirect("https://console.test/projects/{$this->project->id}?git_error=github_unverified#git");

        $this->assertSame(0, GitInstallation::count());
    }

    public function test_without_the_oauth_code_or_a_valid_state_nothing_is_linked(): void
    {
        $github = $this->provider(GitProvider::GITHUB);

        $this->get('/api/git/github/setup?'.http_build_query(['installation_id' => '777', 'state' => $this->state($github)]))
            ->assertRedirect("https://console.test/projects/{$this->project->id}?git_error=github_unverified#git");
        $this->get('/api/git/github/setup?'.http_build_query(['installation_id' => '777', 'code' => 'c', 'state' => 'forged']))
            ->assertRedirect('https://console.test/projects?git_error=state');
        $this->get('/api/git/github/setup?'.http_build_query(['installation_id' => '777', 'setup_action' => 'request', 'state' => $this->state($github)]))
            ->assertRedirect("https://console.test/projects/{$this->project->id}?git_error=install_requested#git");

        $this->assertSame(0, GitInstallation::count());
        $this->assertSame([], $this->calls('POST', '/login/oauth/access_token'));
    }

    public function test_the_gitlab_callback_stores_the_token_for_its_user(): void
    {
        $gitlab = $this->provider(GitProvider::GITLAB, ['client_id' => 'app', 'client_secret' => 'secret']);
        $this->httpResponses = [
            'POST /oauth/token' => [200, ['access_token' => 'oauth-token', 'refresh_token' => 'refresh', 'expires_in' => 7200]],
        ];

        $response = $this->get('/api/git/oauth/gitlab/callback?'.http_build_query(['code' => 'c', 'state' => $this->state($gitlab)]));

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith("https://console.test/projects/{$this->project->id}?gitlab_token_ref=", $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame($gitlab->id, $query['git_provider']);

        $stored = decrypt(Cache::get(RepoConnector::tokenCacheKey($query['gitlab_token_ref'])));
        $this->assertSame('oauth-token', $stored['access_token']);
        $this->assertSame((string) $this->owner->id, $stored['user_id']);

        $exchange = $this->calls('POST', '/oauth/token')[0];
        $this->assertStringStartsWith('https://gitlab.test/oauth/token', $exchange['url']);
    }

    public function test_a_denied_gitlab_sign_in_returns_an_error(): void
    {
        $gitlab = $this->provider(GitProvider::GITLAB, ['client_id' => 'app', 'client_secret' => 'secret']);

        $this->get('/api/git/oauth/gitlab/callback?'.http_build_query(['error' => 'access_denied', 'state' => $this->state($gitlab)]))
            ->assertRedirect("https://console.test/projects/{$this->project->id}?git_error=gitlab_denied#git");
    }
}
