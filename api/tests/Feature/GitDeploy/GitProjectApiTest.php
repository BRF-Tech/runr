<?php

namespace Tests\Feature\GitDeploy;

use App\Models\Git\GitDeploy;
use App\Models\Git\GitInstallation;
use App\Models\Git\GitProvider;
use App\Models\Git\GitRepoLink;
use App\Models\Project;
use App\Models\User;
use App\Services\GitDeploy\OAuthState;
use App\Services\GitDeploy\RepoConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\GitDeployFixtures;
use Tests\TestCase;

/**
 * The Git tab API: payload (remote URL, settings, abilities), settings validation and
 * permissions, connecting / disconnecting GitHub, GitLab and Forgejo repositories (credentials
 * encrypted, webhooks registered and removed), installation and sign-in URLs, deploy history.
 */
class GitProjectApiTest extends TestCase
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

    private function url(string $suffix = ''): string
    {
        return "/api/projects/{$this->project->id}/git{$suffix}";
    }

    public function test_the_git_tab_payload(): void
    {
        $this->provider(GitProvider::FORGEJO);
        $this->provider(GitProvider::GITHUB, ['app_slug' => null]); // incomplete: not offered

        $this->signedIn($this->owner)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.remote_url', 'https://console.test/git/runr/orders.git')
            ->assertJsonPath('data.read_only', false)
            ->assertJsonPath('data.settings.deploy_branch', 'main')
            ->assertJsonPath('data.settings.auto_deploy', true)
            ->assertJsonPath('data.settings.previews_enabled', false)
            ->assertJsonPath('data.connection', null)
            ->assertJsonCount(1, 'data.providers')
            ->assertJsonPath('data.providers.0.type', 'forgejo')
            ->assertJsonPath('data.providers.0.connect_modes', ['pat'])
            ->assertJsonPath('data.abilities', ['pull' => true, 'push' => true, 'manage' => true, 'manage_previews' => true]);
    }

    public function test_settings_are_validated_and_saved(): void
    {
        $owner = $this->signedIn($this->owner);

        $owner->putJson($this->url(), ['deploy_branch' => 'bad..branch'])->assertUnprocessable()->assertJsonValidationErrors('deploy_branch');
        $owner->putJson($this->url(), ['root_dir' => '../escape'])->assertUnprocessable()->assertJsonValidationErrors('root_dir');
        $owner->putJson($this->url(), ['preview_limit' => 999])->assertUnprocessable()->assertJsonValidationErrors('preview_limit');
        $owner->putJson($this->url(), ['preview_env' => ['PORT' => '1']])->assertUnprocessable()->assertJsonValidationErrors('preview_env');

        $owner->putJson($this->url(), [
            'deploy_branch' => 'release/v2',
            'root_dir' => '/services/orders/',
            'auto_deploy' => false,
            'previews_enabled' => true,
            'preview_limit' => 5,
            'preview_ttl_hours' => 24,
            'preview_env' => ['API_URL' => 'https://staging.example.com'],
        ])->assertOk()
            ->assertJsonPath('data.settings.deploy_branch', 'release/v2')
            ->assertJsonPath('data.settings.root_dir', 'services/orders')
            ->assertJsonPath('data.settings.auto_deploy', false)
            ->assertJsonPath('data.settings.preview_limit', 5)
            ->assertJsonPath('data.settings.preview_env.API_URL', 'https://staging.example.com')
            ->assertJsonPath('data.remote_url', 'https://console.test/git/runr/orders.git');
    }

    public function test_viewers_read_and_developers_change(): void
    {
        $viewer = $this->userWithRole('viewer');
        $developer = $this->userWithRole('developer');

        $this->signedIn($viewer)->getJson($this->url())->assertOk()->assertJsonPath('data.abilities.manage', false);
        $this->signedIn($viewer)->putJson($this->url(), ['auto_deploy' => false])->assertForbidden();
        $this->signedIn($viewer)->getJson($this->url('/deploys'))->assertOk();
        $this->signedIn($viewer)->postJson($this->url('/redeploy'))->assertForbidden();

        $this->signedIn($developer)->putJson($this->url(), ['auto_deploy' => false, 'previews_enabled' => true])->assertOk();
    }

    public function test_preview_settings_need_previews_manage(): void
    {
        $role = $this->customRole('git-maintainer', ['git.manage']);
        $user = $this->userWithRole($role->key);

        $this->signedIn($user)->putJson($this->url(), ['auto_deploy' => false])->assertOk();
        $this->signedIn($user)->putJson($this->url(), ['previews_enabled' => true])->assertForbidden();
    }

    public function test_connecting_a_forgejo_repository(): void
    {
        $forgejo = $this->provider(GitProvider::FORGEJO);
        $this->httpResponses = [
            'GET /api/v1/user/repos' => [200, [['id' => 4242, 'full_name' => 'acme/orders', 'html_url' => 'https://forgejo.test/acme/orders', 'default_branch' => 'main', 'private' => true, 'permissions' => ['admin' => true]]]],
            'GET /api/v1/repos/acme/orders' => [200, ['id' => 4242, 'full_name' => 'acme/orders', 'html_url' => 'https://forgejo.test/acme/orders', 'default_branch' => 'main', 'private' => true]],
            'POST /api/v1/repos/acme/orders/hooks' => [201, ['id' => 31]],
        ];

        $this->signedIn($this->owner)->postJson($this->url('/repos'), ['provider_id' => $forgejo->id, 'access_token' => 'pat-secret'])
            ->assertOk()->assertJsonPath('data.0.full_name', 'acme/orders');

        $this->signedIn($this->owner)->postJson($this->url('/connect'), ['provider_id' => $forgejo->id, 'repository' => 'acme/orders', 'access_token' => 'pat-secret'])
            ->assertCreated()
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.connection.repo_full_name', 'acme/orders')
            ->assertJsonPath('data.connection.webhook_registered', true)
            ->assertJsonMissingPath('data.connection.access_token');

        $link = GitRepoLink::firstOrFail();
        $this->assertSame('pat-secret', $link->access_token);
        $this->assertSame('31', $link->external_hook_id);
        $raw = DB::table('git_repo_links')->first();
        $this->assertNotSame('pat-secret', $raw->access_token);           // encrypted at rest
        $this->assertStringNotContainsString('pat-secret', (string) $raw->access_token);

        $hook = $this->calls('POST', '/repos/acme/orders/hooks')[0]['body'];
        $this->assertSame("https://console.test/api/git/webhooks/forgejo/{$link->id}", $hook['config']['url']);
        $this->assertSame($link->webhook_secret, $hook['config']['secret']);
        $this->assertSame(['push', 'pull_request', 'pull_request_sync'], $hook['events']);

        // One repository per project
        $this->signedIn($this->owner)->postJson($this->url('/connect'), ['provider_id' => $forgejo->id, 'repository' => 'acme/orders', 'access_token' => 'x'])
            ->assertUnprocessable();

        // Disconnect removes the webhook and the link
        $this->signedIn($this->owner)->deleteJson($this->url('/connect'))->assertOk()->assertJsonPath('data.connection', null);
        $this->assertNotEmpty($this->calls('DELETE', '/api/v1/repos/acme/orders/hooks/31'));
        $this->assertSame(0, GitRepoLink::count());
    }

    public function test_a_repository_the_token_cannot_read_is_refused(): void
    {
        $forgejo = $this->provider(GitProvider::FORGEJO);
        $this->httpResponses = ['GET /api/v1/repos/acme/secret' => [404, ['message' => 'not found']]];

        $this->signedIn($this->owner)->postJson($this->url('/connect'), ['provider_id' => $forgejo->id, 'repository' => 'acme/secret', 'access_token' => 'pat'])
            ->assertUnprocessable()->assertJsonValidationErrors('repository');
        $this->assertSame(0, GitRepoLink::count());
    }

    public function test_connecting_a_gitlab_project_registers_the_hook_with_its_token(): void
    {
        $gitlab = $this->provider(GitProvider::GITLAB);
        $this->httpResponses = [
            'GET /api/v4/projects/acme%2Forders' => [200, ['id' => 4242, 'path_with_namespace' => 'acme/orders', 'web_url' => 'https://gitlab.test/acme/orders', 'default_branch' => 'main', 'visibility' => 'private']],
            'POST /api/v4/projects/4242/hooks' => [201, ['id' => 77]],
        ];

        $this->signedIn($this->owner)->postJson($this->url('/connect'), ['provider_id' => $gitlab->id, 'repository' => 'acme/orders', 'access_token' => 'glpat-secret'])
            ->assertCreated();

        $link = GitRepoLink::firstOrFail();
        $hook = $this->calls('POST', '/api/v4/projects/4242/hooks')[0]['body'];
        $this->assertSame("https://console.test/api/git/webhooks/gitlab/{$link->id}", $hook['url']);
        $this->assertSame($link->webhook_secret, $hook['token']);
        $this->assertTrue($hook['push_events'] && $hook['merge_requests_events']);
    }

    public function test_the_gitlab_sign_in_token_is_used_once_by_its_user(): void
    {
        $gitlab = $this->provider(GitProvider::GITLAB, ['client_id' => 'app-id', 'client_secret' => 'app-secret']);
        Cache::put(RepoConnector::tokenCacheKey('ref-1'), encrypt([
            'access_token' => 'oauth-token', 'refresh_token' => 'refresh', 'expires_in' => 7200,
            'user_id' => (string) $this->owner->id, 'tenant_id' => null, 'provider_id' => $gitlab->id,
        ]), now()->addMinutes(5));
        $this->httpResponses = [
            'GET /api/v4/projects/4242' => [200, ['id' => 4242, 'path_with_namespace' => 'acme/orders', 'default_branch' => 'main']],
            'POST /api/v4/projects/4242/hooks' => [201, ['id' => 78]],
        ];

        $other = $this->userWithRole('admin');
        $this->signedIn($other)->postJson($this->url('/connect'), ['provider_id' => $gitlab->id, 'repository' => '4242', 'token_ref' => 'ref-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('token_ref');

        $this->signedIn($this->owner)->postJson($this->url('/connect'), ['provider_id' => $gitlab->id, 'repository' => '4242', 'token_ref' => 'ref-1'])
            ->assertCreated();

        $link = GitRepoLink::firstOrFail();
        $this->assertSame('oauth', $link->auth_mode);
        $this->assertSame('oauth-token', $link->access_token);
        $this->assertNull(Cache::get(RepoConnector::tokenCacheKey('ref-1')));
    }

    public function test_github_connects_through_a_linked_installation_only(): void
    {
        $github = $this->provider(GitProvider::GITHUB);
        $this->httpResponses = [
            'POST /app/installations/777/access_tokens' => [201, ['token' => 'ghs_x']],
            'GET /installation/repositories' => [200, ['repositories' => [['id' => 4242, 'full_name' => 'acme/orders', 'default_branch' => 'main', 'private' => true]]]],
            'GET /repos/acme/orders' => [200, ['id' => 4242, 'full_name' => 'acme/orders', 'html_url' => 'https://github.com/acme/orders', 'default_branch' => 'main']],
        ];

        $this->signedIn($this->owner)->postJson($this->url('/repos'), ['provider_id' => $github->id, 'installation_id' => '777'])
            ->assertUnprocessable()->assertJsonValidationErrors('installation_id');

        GitInstallation::create(['git_provider_id' => $github->id, 'installation_id' => '777', 'account_login' => 'acme', 'tenant_id' => null]);

        $this->signedIn($this->owner)->getJson($this->url('/installations')."?provider_id={$github->id}")
            ->assertOk()->assertJsonPath('data.0.account_login', 'acme');
        $this->signedIn($this->owner)->postJson($this->url('/repos'), ['provider_id' => $github->id, 'installation_id' => '777', 'search' => 'ord'])
            ->assertOk()->assertJsonPath('data.0.full_name', 'acme/orders');
        $this->signedIn($this->owner)->postJson($this->url('/connect'), ['provider_id' => $github->id, 'installation_id' => '777', 'repository' => 'acme/orders'])
            ->assertCreated()->assertJsonPath('data.connection.provider_type', 'github');

        $this->assertNull(GitRepoLink::firstOrFail()->webhook_secret); // the app's own webhook
        $this->assertSame([], $this->calls('POST', '/hooks'));
    }

    public function test_installation_and_sign_in_urls_carry_a_signed_state(): void
    {
        $github = $this->provider(GitProvider::GITHUB);
        $gitlab = $this->provider(GitProvider::GITLAB, ['client_id' => 'app-id', 'client_secret' => 'app-secret']);

        $install = $this->signedIn($this->owner)->postJson($this->url('/github/install'), ['provider_id' => $github->id])->assertOk()->json('url');
        $this->assertStringStartsWith('https://github.com/apps/runr-test/installations/new?state=', $install);
        parse_str((string) parse_url($install, PHP_URL_QUERY), $query);
        $this->assertSame(['user_id' => (string) $this->owner->id, 'tenant_id' => null, 'project_id' => $this->project->id, 'provider_id' => $github->id], OAuthState::read($query['state']));

        $signIn = $this->signedIn($this->owner)->postJson($this->url('/gitlab/authorize'), ['provider_id' => $gitlab->id])->assertOk()->json('url');
        $this->assertStringStartsWith('https://gitlab.test/oauth/authorize?', $signIn);
        $this->assertStringContainsString('redirect_uri='.rawurlencode('https://console.test/api/git/oauth/gitlab/callback'), $signIn);
        $this->assertStringContainsString('scope=api', $signIn);

        $plain = $this->provider(GitProvider::GITLAB, ['name' => 'Plain']);
        $this->signedIn($this->owner)->postJson($this->url('/gitlab/authorize'), ['provider_id' => $plain->id])->assertUnprocessable();
    }

    public function test_the_deploy_history(): void
    {
        GitDeploy::create(['project_id' => $this->project->id, 'kind' => 'production', 'trigger' => 'push', 'provider' => 'runr', 'sha' => str_repeat('a', 40), 'branch' => 'main', 'author_name' => 'Ada', 'status' => 'active', 'details' => ['warnings' => ['w1'], 'skipped' => [], 'applied' => ['env_vars' => []]]]);
        GitDeploy::create(['project_id' => $this->project->id, 'kind' => 'preview', 'trigger' => 'pull_request', 'provider' => 'github', 'sha' => str_repeat('b', 40), 'pr_number' => 4, 'status' => 'failed', 'status_message' => 'boom']);

        $this->signedIn($this->owner)->getJson($this->url('/deploys'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->signedIn($this->owner)->getJson($this->url('/deploys').'?kind=production')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.short_sha', 'aaaaaaa')
            ->assertJsonPath('data.0.warnings', ['w1']);
    }

    public function test_redeploy_needs_a_head(): void
    {
        $this->signedIn($this->owner)->postJson($this->url('/redeploy'))->assertUnprocessable();
    }
}
