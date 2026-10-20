<?php

namespace Tests\Feature\GitDeploy;

use App\Events\DeploymentCreated;
use App\Models\ContainerConfig;
use App\Models\Git\GitDeploy;
use App\Models\Git\GitProvider;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\GitDeploy\Providers\ProviderClient;
use App\Services\GitDeploy\StatusReporter;
use App\Services\Projects\ProjectStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\GitDeployFixtures;
use Tests\TestCase;

/**
 * A git deploy that a newer deploy replaced before it went live ends as skipped; the commit status
 * on the provider ends with it instead of staying "pending" forever: GitLab canceled,
 * Forgejo/Gitea warning, GitHub success with the description (its commit statuses have no
 * canceled state), and the GitHub deployment it started becomes inactive.
 */
class ReplacedDeployStatusTest extends TestCase
{
    use ActsWithRoles, GitDeployFixtures, RefreshDatabase;

    private const SHA = 'e40867b14bb761a232cd80139fbd4c0d33264244';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGitDeploy();
        // The Knative deploy itself is not under test
        Event::fake([DeploymentCreated::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownGitDeploy();
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> type, statuses URL, state field, state */
    public static function providers(): array
    {
        return [
            'GitHub' => [GitProvider::GITHUB, '/repos/acme/orders/statuses/'.self::SHA, 'state', 'success'],
            'GitLab' => [GitProvider::GITLAB, '/api/v4/projects/4242/statuses/'.self::SHA, 'state', 'canceled'],
            'Forgejo / Gitea' => [GitProvider::FORGEJO, '/api/v1/repos/acme/orders/statuses/'.self::SHA, 'state', 'warning'],
        ];
    }

    #[DataProvider('providers')]
    public function test_a_replaced_deploy_ends_its_commit_status(string $type, string $statusesUrl, string $field, string $state): void
    {
        [$project, $version, $deploy] = $this->deployingGitDeploy($type);

        Carbon::setTestNow('2026-10-19 09:00:05');
        app(DeploymentStarter::class)->start($project, $version, 'dev');

        $this->assertSame(GitDeploy::SKIPPED, $deploy->fresh()->status);
        $statuses = $this->calls('POST', $statusesUrl);
        $this->assertNotEmpty($statuses, 'the skipped deploy is reported');
        $this->assertSame($state, end($statuses)['body'][$field]);
        $this->assertSame('Replaced by a newer deploy', end($statuses)['body']['description']);
        $this->assertSame(ProviderClient::CANCELED.'|'.GitDeploy::SKIPPED, $deploy->fresh()->reported_state);
    }

    public function test_the_github_deployment_of_a_replaced_deploy_becomes_inactive(): void
    {
        [$project, $version, $deploy] = $this->deployingGitDeploy(GitProvider::GITHUB);
        $deploy->forceFill(['provider_deployment_id' => '555'])->saveQuietly();

        Carbon::setTestNow('2026-10-19 09:00:05');
        app(DeploymentStarter::class)->start($project, $version, 'dev');

        $deploymentStatuses = $this->calls('POST', '/repos/acme/orders/deployments/555/statuses');
        $this->assertSame('inactive', end($deploymentStatuses)['body']['state']);
        $this->assertSame('Replaced by a newer deploy', end($deploymentStatuses)['body']['description']);
        $this->assertSame([], $this->deploymentsStarted(), 'no new deployment record for a deploy that never went live');
    }

    public function test_a_replaced_deploy_without_a_github_deployment_starts_none(): void
    {
        [$project, $version] = $this->deployingGitDeploy(GitProvider::GITHUB);

        Carbon::setTestNow('2026-10-19 09:00:05');
        app(DeploymentStarter::class)->start($project, $version, 'dev');

        $this->assertNotEmpty($this->calls('POST', '/repos/acme/orders/statuses/'.self::SHA));
        $this->assertSame([], $this->deploymentsStarted());
    }

    public function test_a_deploy_of_a_project_stopped_while_it_started_says_so(): void
    {
        [$project, , $deploy] = $this->deployingGitDeploy(GitProvider::GITLAB);

        // The stop takes the starting deployment down; no newer deployment follows it
        app(ProjectStopService::class)->stop($project, $this->userWithRole('owner'), 'maintenance');

        $this->assertSame(GitDeploy::SKIPPED, $deploy->fresh()->status);
        $statuses = $this->calls('POST', '/api/v4/projects/4242/statuses/'.self::SHA);
        $this->assertSame('canceled', end($statuses)['body']['state']);
        $this->assertSame('Stopped before it went live', end($statuses)['body']['description']);
    }

    public function test_skipped_is_a_final_commit_state(): void
    {
        $reporter = app(StatusReporter::class);

        $this->assertSame(ProviderClient::CANCELED, $reporter->commitState(GitDeploy::SKIPPED));
        $this->assertSame(ProviderClient::PENDING, $reporter->commitState(GitDeploy::DEPLOYING));
        $this->assertSame(ProviderClient::SUCCESS, $reporter->commitState(GitDeploy::ACTIVE));
        $this->assertSame(ProviderClient::FAILURE, $reporter->commitState(GitDeploy::FAILED));
        foreach (GitDeploy::FINAL as $status) {
            $this->assertNotSame(ProviderClient::PENDING, $reporter->commitState($status), "{$status} ends the commit status");
        }
    }

    /** @return list<array{method: string, url: string, body: array}> GitHub deployments created */
    private function deploymentsStarted(): array
    {
        return array_values(array_filter($this->calls('POST', '/repos/acme/orders/deployments'), fn ($c) => str_ends_with($c['url'], '/deployments')));
    }

    /**
     * A project linked to a repository of the provider, with a git deploy whose deployment is
     * starting (pending) since 09:00.
     *
     * @return array{0: Project, 1: Version, 2: GitDeploy}
     */
    private function deployingGitDeploy(string $type): array
    {
        $region = KnativeRegion::create(['name' => 'eu-'.$type, 'project_namespace' => 'fn', 'is_active' => true]);
        $project = Project::create(['name' => 'orders', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
            'trigger_type' => 'http', 'timeout_seconds' => 30, 'scale_to_zero' => false, 'min_scale' => 1, 'max_scale' => 1]);
        $this->link($project, $this->provider($type));
        $this->httpResponses = [
            'POST /app/installations/777/access_tokens' => [201, ['token' => 'ghs_test']],
        ];

        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'git', 'build_status' => 'ready']);
        Carbon::setTestNow('2026-10-19 09:00:00');
        $deployment = $project->deployments()->create(['version_id' => $version->id, 'status' => 'pending', 'k8s_job_name' => 'run-orders-v1', 'deployed_by' => 'git']);
        $deploy = GitDeploy::create([
            'project_id' => $project->id, 'version_id' => $version->id, 'deployment_id' => $deployment->id,
            'kind' => GitDeploy::KIND_PRODUCTION, 'trigger' => 'push', 'provider' => $type,
            'sha' => self::SHA, 'branch' => 'main', 'author_name' => 'Ada', 'status' => GitDeploy::DEPLOYING,
        ]);

        return [$project->fresh(), $version, $deploy];
    }
}
