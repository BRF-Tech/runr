<?php

namespace Tests\Feature\GitDeploy;

use App\Jobs\GitDeploy\CleanupPreviewsJob;
use App\Models\Git\GitDeploy;
use App\Models\Git\GitProvider;
use App\Models\Git\PreviewEnvironment;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\User;
use App\Services\GitDeploy\PreviewService;
use App\Services\GitDeploy\PreviewVersionScope;
use App\Services\GitDeploy\ProjectRemote;
use App\Services\GitDeploy\Providers\TarGz;
use App\Services\Helpers\ResourceReservation;
use App\Services\Projects\ProjectStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Tenancy\Services\TenantQuotaService;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\GitDeployFixtures;
use Tests\TestCase;

/**
 * Preview environments of pull requests (GitHub App webhooks, fake region): created on open,
 * redeployed on new commits, deleted on close/merge and after their TTL; the fork rule, the
 * per-project limit, the quota share, the PR comment and statuses; preview versions stay out of
 * the project's own versions.
 */
class PreviewLifecycleTest extends TestCase
{
    use ActsWithRoles, GitDeployFixtures, RefreshDatabase;

    private User $owner;

    private Project $project;

    private GitProvider $githubApp;

    private const HEAD_1 = 'b20867b14bb761a232cd80139fbd4c0d33264241';

    private const HEAD_2 = 'c30867b14bb761a232cd80139fbd4c0d33264242';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGitDeploy();
        $this->requireGitBackend();

        $this->owner = $this->userWithRole('owner');
        $this->project = $this->faasProject($this->clusterRegion());
        $this->githubApp = $this->provider(GitProvider::GITHUB);
        $this->link($this->project, $this->githubApp);
        $this->gitSettings($this->project, [
            'previews_enabled' => true,
            'preview_limit' => 3,
            'preview_env' => ['API_URL' => 'https://staging.example.com'],
        ]);

        $this->httpResponses = [
            'POST /app/installations/777/access_tokens' => [201, ['token' => 'ghs_test']],
            'GET /repos/acme/orders/tarball/'.self::HEAD_1 => [200, $this->archive('v1')],
            'GET /repos/acme/orders/tarball/'.self::HEAD_2 => [200, $this->archive('v2')],
            'GET /repos/acme/orders/tarball/' => [200, $this->archive('fork')],
            'POST /repos/acme/orders/deployments/777001/statuses' => [201, ['id' => 1]],
            'POST /repos/acme/orders/deployments' => [201, ['id' => 777001]],
            'POST /repos/acme/orders/issues/' => [201, ['id' => 555123]],
        ];
    }

    protected function tearDown(): void
    {
        $this->tearDownGitDeploy();
        parent::tearDown();
    }

    private function archive(string $body): string
    {
        return TarGz::create([
            'main.py' => "def handler(event, context):\n    return {\"statusCode\": 200, \"body\": \"{$body}\"}\n",
        ], 'acme-orders-head/');
    }

    private function deliver(string $fixture, array $patch = [], ?string $delivery = null): TestResponse
    {
        $payload = array_replace_recursive(json_decode(self::fixture("github/{$fixture}.json"), true), $patch);
        $body = json_encode($payload);

        return $this->call('POST', "/api/git/webhooks/github/{$this->githubApp->id}", [], [], [], $this->server([
            'X-GitHub-Event' => 'pull_request',
            'X-GitHub-Delivery' => $delivery ?? uniqid('d-', true),
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'github-webhook-secret'),
            'Content-Type' => 'application/json',
        ]), $body);
    }

    private function servicePatches(string $name): array
    {
        // Server-side apply: PATCH …/services/<name>?fieldManager=runr (exact name, not a prefix)
        return $this->calls('PATCH', "/apis/serving.knative.dev/v1/namespaces/fn/services/{$name}?");
    }

    public function test_an_opened_pull_request_gets_a_preview(): void
    {
        $this->deliver('pull_request.opened')->assertStatus(202);

        $preview = PreviewEnvironment::where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame(PreviewEnvironment::READY, $preview->status);
        $this->assertSame(12, $preview->number);
        $this->assertSame('orders-pr-12', $preview->service_name);
        $this->assertSame('https://orders-pr-12.eu.runr.test', $preview->url);
        $this->assertSame(self::HEAD_1, $preview->head_sha);
        $this->assertFalse($preview->is_fork);
        $this->assertTrue($preview->expires_at->isFuture());

        // Its own Knative Service: preview labels, scale to zero, the overrides
        $service = $this->servicePatches('orders-pr-12')[0]['body'];
        $this->assertSame($this->project->id, $service['metadata']['labels']['runr/preview-of']);
        $this->assertArrayNotHasKey('runr/project-id', $service['metadata']['labels']);
        $template = $service['spec']['template'];
        $this->assertArrayNotHasKey('autoscaling.knative.dev/min-scale', $template['metadata']['annotations']);
        $env = array_column($template['spec']['containers'][0]['env'], 'value', 'name');
        $this->assertSame('https://staging.example.com', $env['API_URL']);
        $this->assertSame('hello', $env['GREETING']);
        $this->assertSame('12', $env['RUNR_PREVIEW_PR']);
        $this->assertSame([], $this->servicePatches('orders')); // production untouched

        // It runs the request's code: the ConfigMap it mounts carries the files of the preview
        // commit, read from the project remote (RemoteRepoFiles), not the production version's
        $configMaps = $this->calls('PATCH', '/api/v1/namespaces/fn/configmaps/orders-pr-12-v');
        $this->assertNotEmpty($configMaps, 'the preview code ConfigMap is applied');
        $configMap = end($configMaps)['body'];
        $this->assertStringContainsString('"v1"', $configMap['data']['main.py']);
        $this->assertSame($configMap['metadata']['name'], collect($template['spec']['volumes'])->firstWhere('name', 'code')['configMap']['name']);

        // The preview's code: a commit under refs/runr/previews/12 of the remote
        $remote = app(ProjectRemote::class);
        $sha = $remote->resolve($this->project, 'refs/runr/previews/12');
        $this->assertStringContainsString('"v1"', $remote->tree($this->project, $sha)->files['main.py']);

        // Preview versions stay out of the project's versions
        $this->assertSame(1, PreviewVersionScope::query()->where('project_id', $this->project->id)->count());
        $this->signedIn($this->owner)->getJson("/api/projects/{$this->project->id}/versions")->assertOk()->assertJsonCount(0, 'data');

        // Comment, commit status and a transient GitHub deployment
        $comment = $this->calls('POST', '/repos/acme/orders/issues/12/comments');
        $this->assertNotEmpty($comment);
        $last = $this->calls('PATCH', '/repos/acme/orders/issues/comments/555123');
        $this->assertStringContainsString('https://orders-pr-12.eu.runr.test', end($last)['body']['body']);
        $this->assertStringContainsString('Ready', end($last)['body']['body']);
        $statuses = $this->calls('POST', '/repos/acme/orders/statuses/'.self::HEAD_1);
        $this->assertSame('success', end($statuses)['body']['state']);
        $this->assertSame('https://orders-pr-12.eu.runr.test', end($statuses)['body']['target_url']);
        $deployment = $this->calls('POST', '/repos/acme/orders/deployments')[0]['body'];
        $this->assertSame('preview/pr-12', $deployment['environment']);
        $this->assertTrue($deployment['transient_environment']);
        $this->assertFalse($deployment['production_environment']);

        $this->assertSame(GitDeploy::ACTIVE, GitDeploy::where('kind', 'preview')->firstOrFail()->status);
    }

    public function test_new_commits_redeploy_and_the_old_version_goes(): void
    {
        $this->deliver('pull_request.opened');
        $this->deliver('pull_request.synchronize');

        $preview = PreviewEnvironment::firstOrFail();
        $this->assertSame(self::HEAD_2, $preview->head_sha);
        $this->assertSame(PreviewEnvironment::READY, $preview->status);
        $this->assertCount(2, $this->servicePatches('orders-pr-12'));
        $this->assertSame(1, PreviewVersionScope::query()->where('project_id', $this->project->id)->count());
        // the new commit's code is what the preview now mounts
        $configMaps = $this->calls('PATCH', '/api/v1/namespaces/fn/configmaps/orders-pr-12-v');
        $this->assertStringContainsString('"v2"', end($configMaps)['body']['data']['main.py']);
        $this->assertSame(1, count($this->calls('POST', '/repos/acme/orders/issues/12/comments'))); // updated, not repeated
    }

    public function test_closing_the_request_deletes_the_preview(): void
    {
        $this->deliver('pull_request.opened');
        $this->deliver('pull_request.closed');

        $preview = PreviewEnvironment::firstOrFail();
        $this->assertSame(PreviewEnvironment::DELETED, $preview->status);
        $this->assertSame('Merged', $preview->status_message);
        $this->assertNotEmpty($this->calls('DELETE', '/apis/serving.knative.dev/v1/namespaces/fn/services/orders-pr-12'));
        $this->assertSame(0, PreviewVersionScope::query()->where('project_id', $this->project->id)->count());
        $this->assertNull(app(ProjectRemote::class)->resolve($this->project, 'refs/runr/previews/12'));
        $comment = $this->calls('PATCH', '/issues/comments/555123');
        $this->assertStringContainsString('Deleted', end($comment)['body']['body']);
        $inactive = array_filter($this->calls('POST', '/deployments/777001/statuses'), fn ($c) => $c['body']['state'] === 'inactive');
        $this->assertNotEmpty($inactive);
    }

    public function test_fork_requests_need_fork_previews(): void
    {
        $this->deliver('pull_request.fork');

        $preview = PreviewEnvironment::where('number', 13)->firstOrFail();
        $this->assertSame(PreviewEnvironment::SKIPPED, $preview->status);
        $this->assertTrue($preview->is_fork);
        $this->assertStringContainsString('forks are turned off', $preview->status_message);
        $this->assertSame([], $this->servicePatches('orders-pr-13'));
        $comment = $this->calls('POST', '/repos/acme/orders/issues/13/comments');
        $this->assertStringContainsString('Not deployed', $comment[0]['body']['body']);
    }

    public function test_fork_previews_run_without_secrets(): void
    {
        $this->gitSettings($this->project, ['fork_previews' => true, 'fork_secrets' => false]);

        $this->deliver('pull_request.fork');

        $this->assertSame(PreviewEnvironment::READY, PreviewEnvironment::where('number', 13)->firstOrFail()->status);
        $service = $this->servicePatches('orders-pr-13')[0]['body'];
        $container = $service['spec']['template']['spec']['containers'][0];
        foreach ($container['env'] ?? [] as $env) {
            $this->assertArrayNotHasKey('valueFrom', $env);
        }
        $this->assertArrayNotHasKey('envFrom', $container);
    }

    public function test_secret_env_vars_reach_a_preview_through_its_own_secret_and_never_a_fork(): void
    {
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'API_TOKEN', 'value' => 'tok-preview-31']);
        $this->gitSettings($this->project, ['fork_previews' => true, 'fork_secrets' => false]);

        // Same-repository request: a Secret of the preview, referenced by secretKeyRef
        $this->deliver('pull_request.opened');
        $this->assertNotEmpty($this->calls('PATCH', '/api/v1/namespaces/fn/secrets/orders-pr-12-secret-env'));
        $service = $this->servicePatches('orders-pr-12')[0]['body'];
        $env = array_column($service['spec']['template']['spec']['containers'][0]['env'], null, 'name');
        $this->assertSame(['name' => 'orders-pr-12-secret-env', 'key' => 'API_TOKEN'], $env['API_TOKEN']['valueFrom']['secretKeyRef']);
        $this->assertStringNotContainsString('tok-preview-31', json_encode($service));

        // Fork: no Secret, no reference, a stale one is removed
        $this->deliver('pull_request.fork');
        $this->assertSame([], $this->calls('PATCH', '/secrets/orders-pr-13-secret-env'));
        $this->assertNotEmpty($this->calls('DELETE', '/api/v1/namespaces/fn/secrets/orders-pr-13-secret-env'));
        $fork = $this->servicePatches('orders-pr-13')[0]['body']['spec']['template']['spec']['containers'][0];
        $this->assertNotContains('API_TOKEN', array_column($fork['env'] ?? [], 'name'));
    }

    public function test_the_preview_limit_applies(): void
    {
        $this->gitSettings($this->project, ['preview_limit' => 1]);

        $this->deliver('pull_request.opened');
        $this->deliver('pull_request.opened', ['number' => 14, 'pull_request' => ['number' => 14, 'head' => ['sha' => self::HEAD_2]]]);

        $second = PreviewEnvironment::where('number', 14)->firstOrFail();
        $this->assertSame(PreviewEnvironment::SKIPPED, $second->status);
        $this->assertStringContainsString('its limit is 1', $second->status_message);
        $this->assertSame([], $this->servicePatches('orders-pr-14'));
    }

    public function test_previews_off_means_no_preview(): void
    {
        $this->gitSettings($this->project, ['previews_enabled' => false]);

        $this->deliver('pull_request.opened')->assertStatus(202);

        $this->assertSame(0, PreviewEnvironment::count());
        $this->assertSame([], $this->calls('GET', '/tarball/'));
    }

    public function test_only_http_projects_get_previews(): void
    {
        $this->project->faasConfig()->update(['trigger_type' => 'schedule', 'schedule' => '*/5 * * * *']);

        $this->deliver('pull_request.opened');

        $this->assertSame(PreviewEnvironment::SKIPPED, PreviewEnvironment::firstOrFail()->status);
    }

    public function test_expired_previews_are_cleaned_up(): void
    {
        $this->deliver('pull_request.opened');
        PreviewEnvironment::query()->update(['expires_at' => now()->subMinute()]);

        CleanupPreviewsJob::dispatchSync();

        $this->assertSame(PreviewEnvironment::DELETED, PreviewEnvironment::firstOrFail()->status);
        $this->assertSame('Expired', PreviewEnvironment::firstOrFail()->status_message);
    }

    public function test_a_preview_deleted_by_hand_comes_back_with_the_next_commit(): void
    {
        $this->deliver('pull_request.opened');
        $preview = PreviewEnvironment::firstOrFail();

        $this->signedIn($this->owner)->deleteJson("/api/projects/{$this->project->id}/previews/{$preview->id}")
            ->assertOk()->assertJsonPath('data.status', 'deleted');
        $this->signedIn($this->owner)->getJson("/api/projects/{$this->project->id}/previews")->assertOk()->assertJsonCount(0, 'data');

        $this->deliver('pull_request.synchronize');

        $this->assertSame(PreviewEnvironment::READY, $preview->fresh()->status);
    }

    public function test_viewers_see_previews_but_cannot_delete_them(): void
    {
        $this->deliver('pull_request.opened');
        $viewer = $this->userWithRole('viewer');
        $preview = PreviewEnvironment::firstOrFail();

        $this->signedIn($viewer)->getJson("/api/projects/{$this->project->id}/previews")->assertOk()->assertJsonCount(1, 'data');
        $this->signedIn($viewer)->deleteJson("/api/projects/{$this->project->id}/previews/{$preview->id}")->assertForbidden();
    }

    public function test_previews_count_against_the_organization_quota(): void
    {
        $this->deliver('pull_request.opened');
        $region = $this->project->knativeRegion;

        $usage = app(TenantQuotaService::class)->currentUsage($region);
        $projects = ResourceReservation::sum(Project::where('knative_region_id', $region->id)->with('faasConfig')->get());
        $preview = PreviewService::reservation($this->project->fresh(['faasConfig', 'knativeRegion']));

        $this->assertSame($projects['cpu_m'] + $preview['cpu_m'], $usage['cpu_m']);
        $this->assertSame($projects['memory_mb'] + $preview['memory_mb'], $usage['memory_mb']);
        $this->assertGreaterThan(0, $preview['cpu_m']);

        // a preview scales to zero: its pod counts against the quota's FaaS overcommit budget
        $demand = PreviewService::demand($this->project->fresh(['faasConfig', 'knativeRegion']));
        $this->assertSame($preview, ResourceReservation::total($demand));
        $this->assertSame($preview['cpu_m'], $demand['burst']['cpu_m']);
        $this->assertSame(ResourceReservation::zero(), $demand['hard']);
    }

    public function test_a_stop_removes_the_previews_and_a_stopped_project_gets_none(): void
    {
        $this->deliver('pull_request.opened');
        $preview = PreviewEnvironment::firstOrFail();
        $this->assertSame(PreviewEnvironment::READY, $preview->status);

        app(ProjectStopService::class)->stop($this->project, $this->owner, 'Unpaid bill');

        $this->assertSame(PreviewEnvironment::DELETED, $preview->fresh()->status);
        $this->assertSame('Project stopped', $preview->fresh()->status_message);
        $this->assertNotEmpty($this->calls('DELETE', '/apis/serving.knative.dev/v1/namespaces/fn/services/orders-pr-12'));

        // A new commit while it is stopped: skipped with the reason, nothing applied
        $patches = count($this->servicePatches('orders-pr-12'));
        $this->deliver('pull_request.synchronize');
        $preview->refresh();
        $this->assertSame(PreviewEnvironment::SKIPPED, $preview->status);
        $this->assertStringContainsString('Unpaid bill', (string) $preview->status_message);
        $this->assertCount($patches, $this->servicePatches('orders-pr-12'));
    }

    public function test_a_preview_deploy_or_redeploy_of_a_stopped_project_is_refused(): void
    {
        $this->deliver('pull_request.opened');
        $preview = PreviewEnvironment::firstOrFail();
        $patches = count($this->servicePatches('orders-pr-12'));

        // Stopped while a redeploy was queued (the stop's own teardown aside)
        $this->project->forceFill(['stopped_at' => now(), 'stop_reason' => 'Abuse report'])->saveQuietly();

        $this->signedIn($this->owner)->postJson("/api/projects/{$this->project->id}/previews/{$preview->id}/redeploy")
            ->assertStatus(409)->assertJsonPath('code', 'project_stopped');

        $preview->forceFill(['status' => PreviewEnvironment::DEPLOYING])->save();
        app(PreviewService::class)->deploy($preview->fresh());

        $this->assertSame(PreviewEnvironment::FAILED, $preview->fresh()->status);
        $this->assertStringContainsString('Abuse report', (string) $preview->fresh()->status_message);
        $this->assertCount($patches, $this->servicePatches('orders-pr-12'), 'nothing was applied');
    }

    public function test_deleting_the_project_removes_its_previews(): void
    {
        $this->deliver('pull_request.opened');

        $this->signedIn($this->owner)->deleteJson("/api/projects/{$this->project->id}")->assertSuccessful();

        $this->assertNotEmpty($this->calls('DELETE', '/services/orders-pr-12'));
    }
}
