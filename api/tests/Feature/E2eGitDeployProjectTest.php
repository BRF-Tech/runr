<?php

namespace Tests\Feature;

use App\Console\Commands\E2eTestCommand;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The git deploy e2e creates its projects through POST /api/projects, which answers with the
 * project itself (ProjectResource unwrapped; ProjectSecretEnvConfigTest reads config.* at the top).
 * The suite read data.id, so every git deploy check stopped at "project could not be created".
 */
class E2eGitDeployProjectTest extends TestCase
{
    private function gitProject(): array|string
    {
        $command = app(E2eTestCommand::class);
        (new \ReflectionProperty($command, 'baseUrl'))->setValue($command, 'http://api.test');
        (new \ReflectionProperty($command, 'token'))->setValue($command, 'token');

        return (new \ReflectionMethod($command, 'gitProject'))->invoke($command, 'git-e2e-remote', '01J9ZREGION000000000000000');
    }

    public function test_it_reads_the_project_the_api_answers_with(): void
    {
        Http::fake(['http://api.test/api/projects' => Http::response(
            ['id' => '01J9ZPROJECT00000000000000', 'slug' => 'git-e2e-remote-ab12', 'config' => ['trigger_type' => 'http']], 201,
        )]);

        $this->assertSame(['id' => '01J9ZPROJECT00000000000000', 'slug' => 'git-e2e-remote-ab12'], $this->gitProject());
    }

    /**
     * Forgejo delivered the push of the README-only first commit (made before the webhook existed)
     * to the webhook created right after it; its failed deploy ("The handler file main.py is not in
     * the tree") was newest when the check looked, and was taken for the deploy of the pushed commit.
     */
    public function test_the_deploy_of_the_pushed_commit_is_the_one_checked(): void
    {
        $rows = [
            ['sha' => 'aaa111', 'status' => 'failed', 'status_message' => 'The handler file main.py is not in the tree'],
            ['sha' => 'bbb222', 'status' => 'active'],
        ];
        $of = fn (array $deploys, string $sha) => (new \ReflectionMethod(E2eTestCommand::class, 'gitDeployOf'))->invoke(null, $deploys, $sha);

        $this->assertSame('active', $of($rows, 'bbb222')['status']);
        $this->assertNull($of([$rows[0]], 'bbb222'));
    }

    /**
     * The suite repoints RunR's Forgejo webhook at the API on the compose network. Forgejo's hook
     * edit without "events" left the hook with push only ("events":["push"] after the edit), so the
     * pull request never reached RunR and git-preview waited 900 s for a preview.
     */
    public function test_the_webhook_redirect_keeps_the_hook_events(): void
    {
        $body = (new \ReflectionMethod(E2eTestCommand::class, 'forgejoHookRedirect'))
            ->invoke(null, ['push', 'pull_request', 'pull_request_sync'], 'http://api/api/git/webhooks/forgejo/01J9ZLINK');

        $this->assertSame(['push', 'pull_request', 'pull_request_sync'], $body['events']);
        $this->assertSame(['url' => 'http://api/api/git/webhooks/forgejo/01J9ZLINK', 'content_type' => 'json'], $body['config']);
        $this->assertTrue($body['active']);
    }

    public function test_a_refused_create_says_why(): void
    {
        Http::fake(['http://api.test/api/projects' => Http::response(['message' => 'The selected runtime is invalid.'], 422)]);

        $result = $this->gitProject();

        $this->assertIsString($result);
        $this->assertStringContainsString('HTTP 422', $result);
        $this->assertStringContainsString('The selected runtime is invalid.', $result);
    }
}
