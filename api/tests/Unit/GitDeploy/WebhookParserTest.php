<?php

namespace Tests\Unit\GitDeploy;

use App\Services\GitDeploy\Providers\GitEvent;
use App\Services\GitDeploy\Providers\WebhookParser;
use Tests\TestCase;

/**
 * Recorded provider payloads (tests/Fixtures/git) → GitEvent: pushes, pull/merge request actions
 * normalized to open / update / close, forks, merges, metadata-only updates.
 */
class WebhookParserTest extends TestCase
{
    private function payload(string $path): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/git/{$path}")), true);
    }

    public function test_github_push(): void
    {
        $e = WebhookParser::parse('github', 'push', $this->payload('github/push.json'));

        $this->assertTrue($e->isPush());
        $this->assertSame('4242', $e->repoId);
        $this->assertSame('acme/orders', $e->repoFullName);
        $this->assertSame('777', $e->installationId);
        $this->assertSame('main', $e->branch);
        $this->assertSame('a10867b14bb761a232cd80139fbd4c0d33264240', $e->after);
        $this->assertFalse($e->deleted);
        $this->assertSame('Ada Lovelace', $e->authorName);
        $this->assertSame('ada@example.com', $e->authorEmail);
        $this->assertStringStartsWith('fix: answer with the order id', $e->message);
        $this->assertSame('https://github.com/acme/orders/commit/a10867b14bb761a232cd80139fbd4c0d33264240', $e->commitUrl);
        $this->assertSame($e->after, $e->sha());
    }

    public function test_github_branch_deletion(): void
    {
        $payload = $this->payload('github/push.json');
        $payload['deleted'] = true;
        $payload['after'] = str_repeat('0', 40);

        $this->assertTrue(WebhookParser::parse('github', 'push', $payload)->deleted);
    }

    public function test_github_pull_request_actions(): void
    {
        $opened = WebhookParser::parse('github', 'pull_request', $this->payload('github/pull_request.opened.json'));
        $this->assertTrue($opened->isPullRequest());
        $this->assertSame(GitEvent::PR_OPEN, $opened->action);
        $this->assertSame(12, $opened->number);
        $this->assertSame('b20867b14bb761a232cd80139fbd4c0d33264241', $opened->headSha);
        $this->assertSame('feature/lookup', $opened->headRef);
        $this->assertSame('main', $opened->baseRef);
        $this->assertSame('grace', $opened->prAuthor);
        $this->assertSame('Add order lookup', $opened->title);
        $this->assertSame('https://github.com/acme/orders/pull/12', $opened->prUrl);
        $this->assertFalse($opened->isFork);

        $sync = WebhookParser::parse('github', 'pull_request', $this->payload('github/pull_request.synchronize.json'));
        $this->assertSame(GitEvent::PR_UPDATE, $sync->action);
        $this->assertSame('c30867b14bb761a232cd80139fbd4c0d33264242', $sync->sha());

        $closed = WebhookParser::parse('github', 'pull_request', $this->payload('github/pull_request.closed.json'));
        $this->assertSame(GitEvent::PR_CLOSE, $closed->action);
        $this->assertTrue($closed->merged);

        $labeled = $this->payload('github/pull_request.opened.json');
        $labeled['action'] = 'labeled';
        $this->assertNull(WebhookParser::parse('github', 'pull_request', $labeled)->action);
    }

    public function test_github_fork_pull_request(): void
    {
        $e = WebhookParser::parse('github', 'pull_request', $this->payload('github/pull_request.fork.json'));

        $this->assertTrue($e->isFork);
        $this->assertSame(13, $e->number);
        $this->assertSame('4242', $e->repoId); // the base repository: the one the project is connected to
    }

    public function test_github_ping_and_other_events(): void
    {
        $this->assertSame(GitEvent::PING, WebhookParser::parse('github', 'ping', $this->payload('github/ping.json'))->kind);
        $this->assertSame(GitEvent::OTHER, WebhookParser::parse('github', 'issues', ['repository' => ['id' => 1]])->kind);
    }

    public function test_gitlab_push_takes_the_checkout_commit(): void
    {
        $e = WebhookParser::parse('gitlab', 'Push Hook', $this->payload('gitlab/push.json'));

        $this->assertTrue($e->isPush());
        $this->assertSame('4242', $e->repoId);
        $this->assertSame('acme/orders', $e->repoFullName);
        $this->assertSame('main', $e->branch);
        $this->assertSame('da1560886d4f094c3e6c9ef40349f7d38b5d27d7', $e->after);
        $this->assertSame('Ada Lovelace', $e->authorName);
        $this->assertSame("fix: answer with the order id\n", $e->message);
    }

    public function test_gitlab_merge_request_actions(): void
    {
        $open = WebhookParser::parse('gitlab', 'Merge Request Hook', $this->payload('gitlab/merge_request.open.json'));
        $this->assertSame(GitEvent::PR_OPEN, $open->action);
        $this->assertSame(7, $open->number);
        $this->assertSame('e70867b14bb761a232cd80139fbd4c0d33264244', $open->headSha);
        $this->assertSame('feature/lookup', $open->headRef);
        $this->assertFalse($open->isFork);

        $update = WebhookParser::parse('gitlab', 'Merge Request Hook', $this->payload('gitlab/merge_request.update.json'));
        $this->assertSame(GitEvent::PR_UPDATE, $update->action);

        // A title change is an "update" without new commits: nothing to redeploy
        $title = WebhookParser::parse('gitlab', 'Merge Request Hook', $this->payload('gitlab/merge_request.title.json'));
        $this->assertNull($title->action);

        $merge = WebhookParser::parse('gitlab', 'Merge Request Hook', $this->payload('gitlab/merge_request.merge.json'));
        $this->assertSame(GitEvent::PR_CLOSE, $merge->action);
        $this->assertTrue($merge->merged);

        $fork = $this->payload('gitlab/merge_request.open.json');
        $fork['object_attributes']['source_project_id'] = 9999;
        $this->assertTrue(WebhookParser::parse('gitlab', null, $fork)->isFork);
    }

    public function test_forgejo_push_and_pull_requests(): void
    {
        $push = WebhookParser::parse('forgejo', 'push', $this->payload('forgejo/push.json'));
        $this->assertTrue($push->isPush());
        $this->assertSame('main', $push->branch);
        $this->assertSame('1a2b3c4d5e6f708192a3b4c5d6e7f80910a1b2c3', $push->after);
        $this->assertSame('Ada Lovelace', $push->authorName);

        $opened = WebhookParser::parse('forgejo', 'pull_request', $this->payload('forgejo/pull_request.opened.json'));
        $this->assertSame(GitEvent::PR_OPEN, $opened->action);
        $this->assertSame(3, $opened->number);
        $this->assertSame('2b3c4d5e6f708192a3b4c5d6e7f80910a1b2c3d4', $opened->headSha);

        $sync = WebhookParser::parse('forgejo', 'pull_request_sync', $this->payload('forgejo/pull_request.synchronized.json'));
        $this->assertSame(GitEvent::PR_UPDATE, $sync->action);

        $closed = WebhookParser::parse('forgejo', 'pull_request', $this->payload('forgejo/pull_request.closed.json'));
        $this->assertSame(GitEvent::PR_CLOSE, $closed->action);
        $this->assertFalse($closed->merged);
    }

    public function test_events_survive_the_queue_payload(): void
    {
        $e = WebhookParser::parse('github', 'pull_request', $this->payload('github/pull_request.opened.json'));

        $this->assertEquals($e, GitEvent::fromArray(json_decode(json_encode($e->toArray()), true)));
    }
}
