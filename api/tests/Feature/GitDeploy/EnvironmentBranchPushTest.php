<?php

namespace Tests\Feature\GitDeploy;

use App\Models\Deployment;
use App\Models\Git\GitDeploy;
use App\Models\Git\GitProvider;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Version;
use App\Services\Environments\EnvironmentManager;
use App\Services\GitDeploy\BranchRules;
use App\Services\GitDeploy\Providers\GitEvent;
use App\Services\GitDeploy\Providers\TarGz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\GitDeployFixtures;
use Tests\TestCase;

/**
 * A branch mapped to an environment (docs/environments.md#git-branches, develop → staging): a push
 * to it deploys that environment — the code becomes a version, the runr.yaml does not change the
 * project's (production's) config — and is reported as a deployment of that environment.
 */
class EnvironmentBranchPushTest extends TestCase
{
    use ActsWithRoles, GitDeployFixtures, RefreshDatabase;

    private Project $project;

    private ProjectEnvironment $staging;

    private const MAIN_PY = "def handler(event, context):\n    return {\"statusCode\": 200, \"body\": \"from develop\"}\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGitDeploy();
        $this->userWithRole('owner');
        $this->project = $this->faasProject($this->demoRegion());
        $this->staging = app(EnvironmentManager::class)->create($this->project, ['name' => 'staging', 'git_branch' => 'develop']);
    }

    protected function tearDown(): void
    {
        $this->tearDownGitDeploy();
        parent::tearDown();
    }

    private function github(string $payload): TestResponse
    {
        $app = GitProvider::where('type', 'github')->firstOrFail();
        $headers = [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'd-env-1',
            'Content-Type' => 'application/json',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, 'github-webhook-secret'),
        ];

        return $this->call('POST', "/api/git/webhooks/github/{$app->id}", [], [], [], $this->server($headers), $payload);
    }

    public function test_a_push_to_the_environment_branch_deploys_the_environment(): void
    {
        $this->requireGitBackend();
        $app = $this->provider(GitProvider::GITHUB);
        $this->link($this->project, $app);
        $this->httpResponses = [
            'POST /app/installations/777/access_tokens' => [201, ['token' => 'ghs_test']],
            'GET /repos/acme/orders/tarball/a10867b14bb761a232cd80139fbd4c0d33264240' => [200, TarGz::create(['main.py' => self::MAIN_PY, 'runr.yaml' => "env:\n  FROM: develop\n"], 'acme-orders-a10867b/')],
            'POST /repos/acme/orders/deployments/555/statuses' => [201, ['id' => 1]],
            'POST /repos/acme/orders/deployments' => [201, ['id' => 555]],
        ];

        $payload = str_replace('"refs/heads/main"', '"refs/heads/develop"', self::fixture('github/push.json'));
        $this->github($payload)->assertStatus(202);

        $deploy = GitDeploy::query()->sole();
        $this->assertSame(GitDeploy::KIND_ENVIRONMENT, $deploy->kind);
        $this->assertSame($this->staging->id, $deploy->environment_id);
        $this->assertSame('develop', $deploy->branch);
        $this->assertSame(GitDeploy::ACTIVE, $deploy->status);

        $deployment = Deployment::query()->findOrFail($deploy->deployment_id);
        $this->assertSame($this->staging->id, $deployment->environment_id);
        $this->assertSame(Version::where('project_id', $this->project->id)->sole()->id, $deployment->version_id);
        $this->assertSame(0, Deployment::query()->whereNull('environment_id')->count(), 'production is not deployed');
        $this->assertArrayNotHasKey('FROM', (array) $this->project->fresh()->faasConfig->env_vars, 'the branch\'s runr.yaml does not change production\'s config');

        // GitHub deployment: the environment's name, not production
        $created = $this->calls('POST', '/repos/acme/orders/deployments');
        $this->assertSame('staging', $created[0]['body']['environment']);
        $this->assertFalse($created[0]['body']['production_environment']);
        $statuses = $this->calls('POST', '/deployments/555/statuses');
        $this->assertSame('https://orders-staging.demo.runr.test', end($statuses)['body']['environment_url']);
    }

    public function test_only_mapped_branches_with_auto_deploy_are_deployed(): void
    {
        $event = fn (string $branch) => GitEvent::fromArray([
            'provider' => 'github', 'kind' => GitEvent::PUSH, 'branch' => $branch, 'after' => str_repeat('a', 40), 'deleted' => false,
        ]);

        $this->assertSame(BranchRules::PRODUCTION, BranchRules::decide($this->project, $event('develop')));
        $this->assertSame(BranchRules::IGNORE, BranchRules::decide($this->project, $event('feature/x')));

        $this->staging->update(['auto_deploy' => false]);
        $this->assertSame(BranchRules::IGNORE, BranchRules::decide($this->project, $event('develop')));
    }
}
