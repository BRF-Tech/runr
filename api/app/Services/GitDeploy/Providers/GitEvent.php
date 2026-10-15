<?php

namespace App\Services\GitDeploy\Providers;

/**
 * A provider webhook in one shape (WebhookParser): a push to a branch or a pull/merge request
 * change. PR actions are normalized: open (opened / reopened), update (new commits), close
 * (closed or merged); everything else is ignored.
 */
final class GitEvent
{
    public const PUSH = 'push';

    public const PR = 'pull_request';

    public const PING = 'ping';

    public const OTHER = 'other';

    public const PR_OPEN = 'open';

    public const PR_UPDATE = 'update';

    public const PR_CLOSE = 'close';

    public function __construct(
        public readonly string $provider,
        public readonly string $kind,
        public readonly ?string $repoId = null,
        public readonly ?string $repoFullName = null,
        public readonly ?string $repoUrl = null,
        public readonly ?string $installationId = null,
        // push
        public readonly ?string $branch = null,
        public readonly ?string $before = null,
        public readonly ?string $after = null,
        public readonly bool $deleted = false,
        // commit (push head / PR head)
        public readonly ?string $authorName = null,
        public readonly ?string $authorEmail = null,
        public readonly ?string $message = null,
        public readonly ?string $commitUrl = null,
        // pull / merge request
        public readonly ?int $number = null,
        public readonly ?string $action = null,
        public readonly bool $merged = false,
        public readonly ?string $headSha = null,
        public readonly ?string $headRef = null,
        public readonly ?string $baseRef = null,
        public readonly bool $isFork = false,
        public readonly ?string $title = null,
        public readonly ?string $prUrl = null,
        public readonly ?string $prAuthor = null,
        public readonly ?string $rawAction = null,
    ) {}

    public function isPush(): bool
    {
        return $this->kind === self::PUSH;
    }

    public function isPullRequest(): bool
    {
        return $this->kind === self::PR;
    }

    /** The commit that would be deployed. */
    public function sha(): ?string
    {
        return $this->isPullRequest() ? $this->headSha : $this->after;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
