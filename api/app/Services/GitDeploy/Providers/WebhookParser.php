<?php

namespace App\Services\GitDeploy\Providers;

use App\Models\Git\GitProvider;

/**
 * Provider webhook payloads → GitEvent.
 *  - GitHub:  X-GitHub-Event push | pull_request | ping
 *  - GitLab:  object_kind push | merge_request (X-Gitlab-Event "Push Hook" / "Merge Request Hook")
 *  - Forgejo: X-Forgejo-Event / X-Gitea-Event push | pull_request (pull_request_sync is a sync)
 */
final class WebhookParser
{
    public static function parse(string $provider, ?string $event, array $payload): GitEvent
    {
        return match ($provider) {
            GitProvider::GITHUB => self::github((string) $event, $payload),
            GitProvider::GITLAB => self::gitlab($payload),
            GitProvider::FORGEJO => self::forgejo((string) $event, $payload),
            default => new GitEvent($provider, GitEvent::OTHER),
        };
    }

    private static function github(string $event, array $p): GitEvent
    {
        $repo = $p['repository'] ?? [];
        $base = [
            'provider' => GitProvider::GITHUB,
            'repoId' => isset($repo['id']) ? (string) $repo['id'] : null,
            'repoFullName' => $repo['full_name'] ?? null,
            'repoUrl' => $repo['html_url'] ?? null,
            'installationId' => isset($p['installation']['id']) ? (string) $p['installation']['id'] : null,
        ];

        if ($event === 'ping') {
            return new GitEvent(...$base, kind: GitEvent::PING);
        }

        if ($event === 'push') {
            $head = $p['head_commit'] ?? [];

            return new GitEvent(
                ...$base,
                kind: GitEvent::PUSH,
                branch: self::branch($p['ref'] ?? null),
                before: $p['before'] ?? null,
                after: $p['after'] ?? null,
                deleted: (bool) ($p['deleted'] ?? false) || self::isZero($p['after'] ?? null),
                authorName: $head['author']['name'] ?? ($p['pusher']['name'] ?? null),
                authorEmail: $head['author']['email'] ?? ($p['pusher']['email'] ?? null),
                message: $head['message'] ?? null,
                commitUrl: $head['url'] ?? null,
            );
        }

        if ($event === 'pull_request') {
            $pr = $p['pull_request'] ?? [];
            $raw = (string) ($p['action'] ?? '');
            $action = match ($raw) {
                'opened', 'reopened', 'ready_for_review' => GitEvent::PR_OPEN,
                'synchronize' => GitEvent::PR_UPDATE,
                'closed' => GitEvent::PR_CLOSE,
                default => null,
            };
            $headRepo = $pr['head']['repo']['id'] ?? null;
            $baseRepo = $pr['base']['repo']['id'] ?? ($repo['id'] ?? null);

            return new GitEvent(
                ...$base,
                kind: GitEvent::PR,
                authorName: $pr['user']['login'] ?? null,
                message: $pr['title'] ?? null,
                number: isset($p['number']) ? (int) $p['number'] : (isset($pr['number']) ? (int) $pr['number'] : null),
                action: $action,
                merged: (bool) ($pr['merged'] ?? false),
                headSha: $pr['head']['sha'] ?? null,
                headRef: $pr['head']['ref'] ?? null,
                baseRef: $pr['base']['ref'] ?? null,
                isFork: $headRepo !== null && $baseRepo !== null && $headRepo !== $baseRepo,
                title: $pr['title'] ?? null,
                prUrl: $pr['html_url'] ?? null,
                prAuthor: $pr['user']['login'] ?? null,
                rawAction: $raw,
            );
        }

        return new GitEvent(...$base, kind: GitEvent::OTHER);
    }

    private static function gitlab(array $p): GitEvent
    {
        $project = $p['project'] ?? [];
        $base = [
            'provider' => GitProvider::GITLAB,
            'repoId' => isset($project['id']) ? (string) $project['id'] : (isset($p['project_id']) ? (string) $p['project_id'] : null),
            'repoFullName' => $project['path_with_namespace'] ?? null,
            'repoUrl' => $project['web_url'] ?? null,
        ];

        $kind = $p['object_kind'] ?? null;

        if ($kind === 'push') {
            $after = $p['checkout_sha'] ?? $p['after'] ?? null;
            $commits = $p['commits'] ?? [];
            $head = null;
            foreach ($commits as $commit) {
                if (($commit['id'] ?? null) === $after) {
                    $head = $commit;
                }
            }
            $head ??= $commits ? end($commits) : [];

            return new GitEvent(
                ...$base,
                kind: GitEvent::PUSH,
                branch: self::branch($p['ref'] ?? null),
                before: $p['before'] ?? null,
                after: $after ?? ($p['after'] ?? null),
                deleted: self::isZero($p['after'] ?? null),
                authorName: $head['author']['name'] ?? ($p['user_name'] ?? null),
                authorEmail: $head['author']['email'] ?? ($p['user_email'] ?? null),
                message: $head['message'] ?? null,
                commitUrl: $head['url'] ?? null,
            );
        }

        if ($kind === 'merge_request') {
            $mr = $p['object_attributes'] ?? [];
            $raw = (string) ($mr['action'] ?? '');
            // "update" fires for title / label changes too: only one with new commits (oldrev) redeploys
            $action = match ($raw) {
                'open', 'reopen' => GitEvent::PR_OPEN,
                'update' => isset($mr['oldrev']) ? GitEvent::PR_UPDATE : null,
                'close', 'merge' => GitEvent::PR_CLOSE,
                default => null,
            };
            $last = $mr['last_commit'] ?? [];

            return new GitEvent(
                ...$base,
                kind: GitEvent::PR,
                authorName: $last['author']['name'] ?? ($p['user']['username'] ?? null),
                authorEmail: $last['author']['email'] ?? null,
                message: $last['message'] ?? null,
                commitUrl: $last['url'] ?? null,
                number: isset($mr['iid']) ? (int) $mr['iid'] : null,
                action: $action,
                merged: $raw === 'merge' || ($mr['state'] ?? null) === 'merged',
                headSha: $last['id'] ?? null,
                headRef: $mr['source_branch'] ?? null,
                baseRef: $mr['target_branch'] ?? null,
                isFork: isset($mr['source_project_id'], $mr['target_project_id']) && $mr['source_project_id'] !== $mr['target_project_id'],
                title: $mr['title'] ?? null,
                prUrl: $mr['url'] ?? null,
                prAuthor: $p['user']['username'] ?? null,
                rawAction: $raw,
            );
        }

        return new GitEvent(...$base, kind: GitEvent::OTHER);
    }

    private static function forgejo(string $event, array $p): GitEvent
    {
        $repo = $p['repository'] ?? [];
        $base = [
            'provider' => GitProvider::FORGEJO,
            'repoId' => isset($repo['id']) ? (string) $repo['id'] : null,
            'repoFullName' => $repo['full_name'] ?? null,
            'repoUrl' => $repo['html_url'] ?? null,
        ];

        if ($event === 'push') {
            $head = $p['head_commit'] ?? (($p['commits'] ?? []) ? end($p['commits']) : []);

            return new GitEvent(
                ...$base,
                kind: GitEvent::PUSH,
                branch: self::branch($p['ref'] ?? null),
                before: $p['before'] ?? null,
                after: $p['after'] ?? null,
                deleted: self::isZero($p['after'] ?? null),
                authorName: $head['author']['name'] ?? ($p['pusher']['full_name'] ?? $p['pusher']['login'] ?? null),
                authorEmail: $head['author']['email'] ?? ($p['pusher']['email'] ?? null),
                message: $head['message'] ?? null,
                commitUrl: $head['url'] ?? null,
            );
        }

        if (in_array($event, ['pull_request', 'pull_request_sync'], true)) {
            $pr = $p['pull_request'] ?? [];
            $raw = (string) ($p['action'] ?? '');
            $action = match (true) {
                $event === 'pull_request_sync', $raw === 'synchronized' => GitEvent::PR_UPDATE,
                in_array($raw, ['opened', 'reopened'], true) => GitEvent::PR_OPEN,
                $raw === 'closed' => GitEvent::PR_CLOSE,
                default => null,
            };
            $headRepo = $pr['head']['repo']['id'] ?? null;
            $baseRepo = $pr['base']['repo']['id'] ?? ($repo['id'] ?? null);

            return new GitEvent(
                ...$base,
                kind: GitEvent::PR,
                authorName: $pr['user']['login'] ?? null,
                message: $pr['title'] ?? null,
                number: isset($p['number']) ? (int) $p['number'] : (isset($pr['number']) ? (int) $pr['number'] : null),
                action: $action,
                merged: (bool) ($pr['merged'] ?? false),
                headSha: $pr['head']['sha'] ?? null,
                headRef: $pr['head']['ref'] ?? null,
                baseRef: $pr['base']['ref'] ?? null,
                isFork: $headRepo !== null && $baseRepo !== null && $headRepo !== $baseRepo,
                title: $pr['title'] ?? null,
                prUrl: $pr['html_url'] ?? null,
                prAuthor: $pr['user']['login'] ?? null,
                rawAction: $raw,
            );
        }

        return new GitEvent(...$base, kind: GitEvent::OTHER);
    }

    private static function branch(?string $ref): ?string
    {
        return $ref && str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : null;
    }

    private static function isZero(?string $sha): bool
    {
        return $sha !== null && $sha !== '' && trim($sha, '0') === '';
    }
}
