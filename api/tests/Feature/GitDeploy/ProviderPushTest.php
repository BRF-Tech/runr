<?php

namespace Tests\Feature\GitDeploy;

use App\Models\Git\GitDeploy;
use App\Models\Git\GitProvider;
use App\Models\Git\GitWebhookDelivery;
use App\Models\Git\VersionGitSource;
use App\Models\Project;
use App\Models\Version;
use App\Services\GitDeploy\Providers\TarGz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\GitDeployFixtures;
use Tests\TestCase;

/**
 * Push-to-deploy from GitHub (App webhook), GitLab (project hook) and Forgejo/Gitea: signature /
 * token verification, replay protection, branch rules, the source archive import, commit
 * statuses and the GitHub Deployments API — every provider call answered by Http::fake.
 */
class ProviderPushTest extends TestCase
{
    use ActsWithRoles, GitDeployFixtures, RefreshDatabase;

    private Project $project;

    private const MAIN_PY = "def handler(event, context):\n    return {\"statusCode\": 200, \"body\": \"from git\"}\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGitDeploy();
        $this->userWithRole('owner');
        $this->project = $this->faasProject($this->demoRegion());
    }

    protected function tearDown(): void
    {
        $this->tearDownGitDeploy();
        parent::tearDown();
    }

    private function archive(array $files = []): string
    {
        return TarGz::create($files ?: ['main.py' => self::MAIN_PY, 'README.md' => "# orders\n", 'runr.yaml' => "env:\n  FROM: git\n"], 'acme-orders-a10867b/');
    }

    private function github(string $event, string $payload, ?string $secret = 'github-webhook-secret', string $delivery = 'd-1', ?GitProvider $app = null): TestResponse
    {
        $app ??= GitProvider::where('type', 'github')->firstOrFail();
        $headers = [
            'X-GitHub-Event' => $event,
            'X-GitHub-Delivery' => $delivery,
            'Content-Type' => 'application/json',
        ];
        if ($secret !== null) {
            $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $payload, $secret);
        }

        return $this->call('POST', "/api/git/webhooks/github/{$app->id}", [], [], [], $this->server($headers), $payload);
    }

    // ── GitHub ──────────────────────────────────────────────────────────────

    public function test_a_github_push_to_the_deploy_branch_deploys_and_reports(): void
    {
        $this->requireGitBackend();
        $app = $this->provider(GitProvider::GITHUB);
        $this->link($this->project, $app);
        $this->httpResponses = [
            'POST /app/installations/777/access_tokens' => [201, ['token' => 'ghs_test']],
            'GET /repos/acme/orders/tarball/a10867b14bb761a232cd80139fbd4c0d33264240' => [200, $this->archive()],
            'POST /repos/acme/orders/deployments/555/statuses' => [201, ['id' => 1]],
            'POST /repos/acme/orders/deployments' => [201, ['id' => 555]],
        ];

        $this->github('push', self::fixture('github/push.json'))->assertStatus(202)->assertJsonPath('projects', 1);

        $version = Version::where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame('fix: answer with the order id', $version->commit_message);
        $source = VersionGitSource::where('version_id', $version->id)->firstOrFail();
        $this->assertSame('github', $source->provider);
        $this->assertSame('https://github.com/acme/orders/commit/a10867b14bb761a232cd80139fbd4c0d33264240', $source->url);
        $this->assertSame('git', $this->project->faasConfig()->first()->env_vars['FROM']);

        $deploy = GitDeploy::firstOrFail();
        $this->assertSame(GitDeploy::ACTIVE, $deploy->status);
        $this->assertSame('github', $deploy->provider);
        $this->assertSame('Ada Lovelace', $deploy->author_name);

        // Commit statuses: pending while it ran, success at the end
        $statuses = $this->calls('POST', '/repos/acme/orders/statuses/a10867b14bb761a232cd80139fbd4c0d33264240');
        $this->assertSame('pending', $statuses[0]['body']['state']);
        $this->assertSame('success', end($statuses)['body']['state']);
        $this->assertSame('runr', end($statuses)['body']['context']);
        $this->assertSame("https://console.test/projects/{$this->project->id}#git", end($statuses)['body']['target_url']);

        // GitHub deployment: production, then success with the function URL
        $created = $this->calls('POST', '/repos/acme/orders/deployments');
        $this->assertSame('production', $created[0]['body']['environment']);
        $this->assertTrue($created[0]['body']['production_environment']);
        $deploymentStatuses = $this->calls('POST', '/deployments/555/statuses');
        $this->assertSame('success', end($deploymentStatuses)['body']['state']);
        $this->assertSame('https://orders.demo.runr.test', end($deploymentStatuses)['body']['environment_url']);
    }

    public function test_a_bad_signature_is_refused_and_audited(): void
    {
        $this->provider(GitProvider::GITHUB);

        $this->github('push', self::fixture('github/push.json'), 'wrong-secret')->assertStatus(401);
        $this->github('push', self::fixture('github/push.json'), null, 'd-2')->assertStatus(401);

        $this->assertSame(0, GitDeploy::count());
        $this->assertSame(0, GitWebhookDelivery::count());
    }

    public function test_a_replayed_delivery_has_no_effect(): void
    {
        $this->requireGitBackend();
        $app = $this->provider(GitProvider::GITHUB);
        $this->link($this->project, $app);
        $this->httpResponses = [
            'POST /app/installations/777/access_tokens' => [201, ['token' => 'ghs_test']],
            'GET /repos/acme/orders/tarball/' => [200, $this->archive()],
        ];

        $this->github('push', self::fixture('github/push.json'), delivery: 'same-delivery')->assertStatus(202);
        $this->github('push', self::fixture('github/push.json'), delivery: 'same-delivery')->assertOk()->assertJsonPath('message', 'Delivery already processed');

        $this->assertSame(1, GitDeploy::count());
    }

    public function test_ping_other_branches_and_unknown_repositories_deploy_nothing(): void
    {
        $app = $this->provider(GitProvider::GITHUB);
        $this->link($this->project, $app);

        $this->github('ping', self::fixture('github/ping.json'))->assertOk()->assertJsonPath('message', 'pong');

        $feature = json_decode(self::fixture('github/push.json'), true);
        $feature['ref'] = 'refs/heads/feature/x';
        $this->github('push', json_encode($feature), delivery: 'd-feature')->assertStatus(202);

        $elsewhere = json_decode(self::fixture('github/push.json'), true);
        $elsewhere['repository']['id'] = 1;
        $this->github('push', json_encode($elsewhere), delivery: 'd-elsewhere')->assertStatus(202)->assertJsonPath('message', 'Ignored');

        $this->assertSame(0, GitDeploy::count());
        $this->assertSame([], $this->calls('GET', '/tarball/'));
    }

    public function test_a_disabled_github_app_answers_not_found(): void
    {
        $app = $this->provider(GitProvider::GITHUB, ['is_enabled' => false]);

        $this->github('push', self::fixture('github/push.json'), app: $app)->assertNotFound();
    }

    public function test_one_push_deploys_every_project_of_the_repository(): void
    {
        $this->requireGitBackend();
        $app = $this->provider(GitProvider::GITHUB);
        $billing = $this->faasProject($this->project->knativeRegion, 'billing');
        $this->link($this->project, $app);
        $this->link($billing, $app);
        $this->gitSettings($this->project, ['root_dir' => 'orders']);
        $this->gitSettings($billing, ['root_dir' => 'billing']);
        $this->httpResponses = [
            'POST /app/installations/777/access_tokens' => [201, ['token' => 'ghs_test']],
            'GET /repos/acme/orders/tarball/' => [200, $this->archive(['orders/main.py' => self::MAIN_PY, 'billing/main.py' => str_replace('from git', 'billing', self::MAIN_PY)])],
        ];

        $this->github('push', self::fixture('github/push.json'))->assertStatus(202)->assertJsonPath('projects', 2);

        $this->assertSame(1, Version::where('project_id', $this->project->id)->count());
        $this->assertSame(1, Version::where('project_id', $billing->id)->count());
    }

    // ── GitLab ──────────────────────────────────────────────────────────────

    public function test_a_gitlab_push_with_the_hook_token_deploys(): void
    {
        $this->requireGitBackend();
        $link = $this->link($this->project, $this->provider(GitProvider::GITLAB));
        $this->httpResponses = [
            'GET /api/v4/projects/4242/repository/archive.tar.gz' => [200, $this->archive()],
        ];
        $payload = self::fixture('gitlab/push.json');

        $this->call('POST', "/api/git/webhooks/gitlab/{$link->id}", [], [], [], $this->server([
            'X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => 'wrong', 'Content-Type' => 'application/json',
        ]), $payload)->assertStatus(401);

        $this->call('POST', "/api/git/webhooks/gitlab/{$link->id}", [], [], [], $this->server([
            'X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => 'link-webhook-secret', 'X-Gitlab-Event-UUID' => 'u-1', 'Content-Type' => 'application/json',
        ]), $payload)->assertStatus(202);

        $archive = $this->calls('GET', '/repository/archive.tar.gz');
        $this->assertStringContainsString('sha=da1560886d4f094c3e6c9ef40349f7d38b5d27d7', $archive[0]['url']);
        $this->assertSame(GitDeploy::ACTIVE, GitDeploy::firstOrFail()->status);

        $statuses = $this->calls('POST', '/api/v4/projects/4242/statuses/da1560886d4f094c3e6c9ef40349f7d38b5d27d7');
        $this->assertSame('running', $statuses[0]['body']['state']);
        $this->assertSame('success', end($statuses)['body']['state']);
        $this->assertSame('runr', end($statuses)['body']['name']);
        $this->assertSame([], $this->calls('POST', '/deployments')); // GitHub only
    }

    // ── Forgejo / Gitea ─────────────────────────────────────────────────────

    public function test_a_forgejo_push_with_the_hmac_signature_deploys(): void
    {
        $this->requireGitBackend();
        $link = $this->link($this->project, $this->provider(GitProvider::FORGEJO));
        $this->httpResponses = [
            'GET /api/v1/repos/acme/orders/archive/1a2b3c4d5e6f708192a3b4c5d6e7f80910a1b2c3.tar.gz' => [200, $this->archive()],
        ];
        $payload = self::fixture('forgejo/push.json');

        $this->call('POST', "/api/git/webhooks/forgejo/{$link->id}", [], [], [], $this->server([
            'X-Forgejo-Event' => 'push', 'X-Forgejo-Signature' => hash_hmac('sha256', $payload, 'not-the-secret'), 'Content-Type' => 'application/json',
        ]), $payload)->assertStatus(401);

        $this->call('POST', "/api/git/webhooks/forgejo/{$link->id}", [], [], [], $this->server([
            'X-Gitea-Event' => 'push', 'X-Gitea-Delivery' => 'g-1', 'X-Gitea-Signature' => hash_hmac('sha256', $payload, 'link-webhook-secret'), 'Content-Type' => 'application/json',
        ]), $payload)->assertStatus(202);

        $this->assertSame(GitDeploy::ACTIVE, GitDeploy::firstOrFail()->status);
        $statuses = $this->calls('POST', '/api/v1/repos/acme/orders/statuses/1a2b3c4d5e6f708192a3b4c5d6e7f80910a1b2c3');
        $this->assertSame('pending', $statuses[0]['body']['state']);
        $this->assertSame('success', end($statuses)['body']['state']);
        $this->assertSame('token pat-token', $this->lastAuthorization('/api/v1/repos/acme/orders/statuses/'));
    }

    public function test_a_failing_import_is_reported_as_a_failure(): void
    {
        $this->requireGitBackend();
        $link = $this->link($this->project, $this->provider(GitProvider::FORGEJO));
        $this->httpResponses = [
            'GET /api/v1/repos/acme/orders/archive/' => [200, $this->archive(['other.py' => "print(1)\n"])],
        ];
        $payload = self::fixture('forgejo/push.json');

        $this->call('POST', "/api/git/webhooks/forgejo/{$link->id}", [], [], [], $this->server([
            'X-Gitea-Event' => 'push', 'X-Gitea-Delivery' => 'g-2', 'X-Gitea-Signature' => hash_hmac('sha256', $payload, 'link-webhook-secret'),
        ]), $payload)->assertStatus(202);

        $this->assertSame(GitDeploy::FAILED, GitDeploy::firstOrFail()->status);
        $statuses = $this->calls('POST', '/statuses/');
        $this->assertSame('failure', end($statuses)['body']['state']);
        $this->assertStringContainsString('main.py', end($statuses)['body']['description']);
    }

    private function lastAuthorization(string $urlPart): ?string
    {
        $match = null;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), $urlPart)) {
                $match = $request->header('Authorization')[0] ?? null;
            }
        }

        return $match;
    }
}
