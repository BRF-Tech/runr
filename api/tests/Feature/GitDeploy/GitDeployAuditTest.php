<?php

namespace Tests\Feature\GitDeploy;

use App\Models\AuditEvent;
use App\Models\Git\GitDeploy;
use App\Models\Git\GitProvider;
use App\Models\Git\ProjectGitSettings;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\Audit\AuditCatalog;
use App\Services\GitDeploy\GitDeployService;
use App\Services\GitDeploy\ProjectRemote;
use App\Services\SupplyChain\DeployPolicyGate;
use App\Services\SupplyChain\PolicyDecision;
use App\Support\Secrets\SecretColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\GitDeployFixtures;
use Tests\TestCase;

/**
 * Git deploy under the security conventions (docs/security.md, docs/git-deploy.md#security):
 * every mutating route is in the audit catalog, pushes / installations / rejected webhooks are
 * explicit audit events, provider credentials and preview env overrides are secret columns,
 * Git tab responses are never cached, and a push-started deploy passes the supply-chain gate.
 */
class GitDeployAuditTest extends TestCase
{
    use ActsWithRoles, GitDeployFixtures, RefreshDatabase;

    private User $owner;

    private Project $project;

    private const MAIN_PY = "def handler(event, context):\n    return {\"statusCode\": 200, \"body\": \"v1\"}\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGitDeploy();
        $this->owner = $this->userWithRole('owner', ['username' => 'ada']);
        $this->project = $this->faasProject($this->demoRegion());
    }

    protected function tearDown(): void
    {
        $this->tearDownGitDeploy();
        parent::tearDown();
    }

    public function test_every_git_route_has_an_audit_action(): void
    {
        $routes = AuditCatalog::routes();

        foreach ([
            'PUT api/projects/{project}/git' => 'git.settings.update',
            'POST api/projects/{project}/git/redeploy' => 'git.redeploy',
            'POST api/projects/{project}/git/connect' => 'git.repo.connect',
            'DELETE api/projects/{project}/git/connect' => 'git.repo.disconnect',
            'POST api/projects/{project}/previews/{preview}/redeploy' => 'git.preview.redeploy',
            'DELETE api/projects/{project}/previews/{preview}' => 'git.preview.delete',
            'POST api/git-providers' => 'git_provider.create',
            'PUT api/git-providers/{provider}' => 'git_provider.update',
            'DELETE api/git-providers/{provider}' => 'git_provider.delete',
        ] as $key => $action) {
            $this->assertArrayHasKey($key, $routes, "{$key} is audited");
            $this->assertSame($action, $routes[$key]->action, $key);
        }

        foreach (['git.push', 'git.installation.link', 'git.webhook.rejected'] as $explicit) {
            $this->assertContains($explicit, AuditCatalog::actions());
        }
    }

    public function test_a_settings_change_is_audited_with_names_only_and_never_cached(): void
    {
        $url = "/api/projects/{$this->project->id}/git";

        $put = $this->signedIn($this->owner)->putJson($url, [
            'auto_deploy' => false,
            'preview_env' => ['API_URL' => 'https://staging.example.com', 'PREVIEW_KEY' => 'preview-value-4711'],
        ])->assertOk();
        $this->assertStringContainsString('no-store', (string) $put->headers->get('Cache-Control'));

        $event = AuditEvent::where('action', 'git.settings.update')->firstOrFail();
        $this->assertSame('success', $event->result);
        $this->assertSame('project', $event->target_type);
        $this->assertSame((string) $this->project->id, (string) $event->target_id);
        $this->assertEqualsCanonicalizing(['API_URL', 'PREVIEW_KEY'], $event->metadata['preview_env']);
        $this->assertStringNotContainsString('preview-value-4711', json_encode(AuditEvent::all()->map->toPublicArray()));

        // Encrypted at rest, read back decrypted
        $raw = DB::table('project_git_settings')->where('project_id', $this->project->id)->value('preview_env');
        $this->assertStringNotContainsString('preview-value-4711', (string) $raw);
        $this->assertSame('preview-value-4711', ProjectGitSettings::for($this->project)->preview_env['PREVIEW_KEY']);

        $response = $this->signedIn($this->owner)->getJson($url)->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNotSame('HIT', $response->headers->get('X-Cache'));
        $deploys = $this->signedIn($this->owner)->getJson("{$url}/deploys")->assertOk();
        $this->assertStringContainsString('no-store', (string) $deploys->headers->get('Cache-Control'));
    }

    public function test_provider_credentials_are_secret_columns_encrypted_at_rest(): void
    {
        $this->assertSame(SecretColumns::TEXT, SecretColumns::central()['git_providers']['private_key']);
        $this->assertSame(SecretColumns::TEXT, SecretColumns::central()['git_providers']['webhook_secret']);
        $this->assertSame(SecretColumns::TEXT, SecretColumns::central()['git_repo_links']['access_token']);
        $this->assertSame(SecretColumns::TEXT, SecretColumns::central()['git_repo_links']['refresh_token']);
        $this->assertArrayNotHasKey('git_providers', SecretColumns::tenant()); // central only
        $this->assertSame(SecretColumns::JSON, SecretColumns::tenant()['project_git_settings']['preview_env']);

        $provider = $this->provider(GitProvider::FORGEJO);
        $link = $this->link($this->project, $provider, ['access_token' => 'pat-secret-0815', 'webhook_secret' => 'hook-secret-0815']);

        $raw = DB::table('git_repo_links')->where('id', $link->id)->first();
        $this->assertStringNotContainsString('pat-secret-0815', (string) $raw->access_token);
        $this->assertStringNotContainsString('hook-secret-0815', (string) $raw->webhook_secret);
        $this->assertSame('pat-secret-0815', $link->fresh()->access_token);

        $github = $this->provider(GitProvider::GITHUB);
        $row = DB::table('git_providers')->where('id', $github->id)->first();
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $row->private_key);
        $this->assertStringNotContainsString('github-webhook-secret', (string) $row->webhook_secret);
    }

    public function test_provider_changes_are_audited_without_secrets(): void
    {
        $id = $this->signedIn($this->owner)->postJson('/api/git-providers', [
            'type' => 'forgejo', 'name' => 'Code', 'base_url' => 'https://code.example.com',
        ])->assertCreated()->json('data.id');

        $this->signedIn($this->owner)->putJson("/api/git-providers/{$id}", ['name' => 'Code', 'base_url' => 'https://code.example.com', 'client_secret' => 'never-logged-77'])->assertOk();
        $this->signedIn($this->owner)->deleteJson("/api/git-providers/{$id}")->assertNoContent();

        $create = AuditEvent::where('action', 'git_provider.create')->firstOrFail();
        $this->assertSame('git_provider', $create->target_type);
        $this->assertSame($id, $create->target_id);
        $this->assertSame(1, AuditEvent::where('action', 'git_provider.update')->count());
        $this->assertSame(1, AuditEvent::where('action', 'git_provider.delete')->count());
        $this->assertStringNotContainsString('never-logged-77', json_encode(AuditEvent::all()->map->toPublicArray()));
    }

    public function test_a_rejected_webhook_is_an_audit_event(): void
    {
        $link = $this->link($this->project, $this->provider(GitProvider::FORGEJO));
        $payload = self::fixture('forgejo/push.json');

        $this->call('POST', "/api/git/webhooks/forgejo/{$link->id}", [], [], [], $this->server([
            'X-Forgejo-Event' => 'push', 'X-Forgejo-Signature' => hash_hmac('sha256', $payload, 'not-the-secret'), 'Content-Type' => 'application/json',
        ]), $payload)->assertStatus(401);

        $event = AuditEvent::where('action', 'git.webhook.rejected')->firstOrFail();
        $this->assertSame('denied', $event->result);
        $this->assertSame('forgejo', $event->metadata['provider_type']);
        $this->assertSame(AuditEvent::ACTOR_ANONYMOUS, $event->actor_type);
    }

    public function test_pushes_are_audited_and_refused_pushes_too(): void
    {
        $this->requireGitBackend();
        $repo = $this->localRepo();
        $sha = $this->commit($repo, ['main.py' => self::MAIN_PY], 'v1');

        // A viewer may clone, not push
        $body = $this->receivePackBody($repo, [[ProjectRemote::ZERO, $sha, 'refs/heads/main']]);
        $this->pushRaw($this->project, $this->tokenFor($this->userWithRole('viewer')), $body)->assertForbidden();
        $denied = AuditEvent::where('action', 'git.push')->where('result', 'denied')->firstOrFail();
        $this->assertSame('permission', $denied->metadata['reason']);

        [$response] = $this->push($this->project, $this->tokenFor($this->owner), $repo, $sha);
        $response->assertOk();

        $event = AuditEvent::where('action', 'git.push')->where('result', 'success')->firstOrFail();
        $this->assertSame(AuditEvent::ACTOR_API_TOKEN, $event->actor_type);
        $this->assertSame((string) $this->owner->id, (string) $event->actor_id);
        $this->assertSame((string) $this->project->id, (string) $event->target_id);
        $this->assertSame($sha, $event->metadata['refs']['refs/heads/main']['after']);
    }

    public function test_a_push_deploy_waits_for_the_security_checks_and_then_continues(): void
    {
        $this->requireGitBackend();
        $this->gatePolicy([['rule' => 'scan_pending', 'message' => 'the vulnerability scan is running', 'enforced' => true]]);

        $repo = $this->localRepo();
        $sha = $this->commit($repo, ['main.py' => self::MAIN_PY], 'v1');
        $this->push($this->project, $this->tokenFor($this->owner), $repo, $sha);

        $deploy = GitDeploy::firstOrFail();
        $this->assertSame(GitDeploy::BUILDING, $deploy->status);
        $this->assertNull($deploy->deployment_id);
        $this->assertStringContainsString('the vulnerability scan is running', (string) $deploy->status_message);

        // The report is final and the gate passes: the deploy goes on
        $this->gatePolicy([]);
        app(GitDeployService::class)->scanFinished($deploy->version_id);

        $deploy->refresh();
        $this->assertSame(GitDeploy::ACTIVE, $deploy->status);
        $this->assertNotNull($deploy->deployment_id);
    }

    public function test_a_version_the_policy_blocks_is_not_deployed(): void
    {
        $this->requireGitBackend();
        $this->gatePolicy([['rule' => 'critical', 'message' => '2 CRITICAL vulnerabilities (allowed: 0)', 'enforced' => true]]);

        $repo = $this->localRepo();
        $sha = $this->commit($repo, ['main.py' => self::MAIN_PY], 'v1');
        $this->push($this->project, $this->tokenFor($this->owner), $repo, $sha);

        $deploy = GitDeploy::firstOrFail();
        $this->assertSame(GitDeploy::FAILED, $deploy->status);
        $this->assertStringContainsString('2 CRITICAL vulnerabilities', (string) $deploy->status_message);
        $this->assertSame(1, Version::where('project_id', $this->project->id)->count()); // the version stays
        $this->assertSame(0, $this->project->deployments()->count());
        $this->assertSame(1, AuditEvent::where('action', 'deploy.policy_blocked')->where('result', 'denied')->count());
    }

    /** @param list<array{rule: string, message: string, enforced: bool}> $violations */
    private function gatePolicy(array $violations): void
    {
        $decision = new PolicyDecision(true, $violations, ['mode' => 'enforce'], null);

        $this->mock(DeployPolicyGate::class, fn ($mock) => $mock->shouldReceive('evaluate')->andReturn($decision));
    }
}
