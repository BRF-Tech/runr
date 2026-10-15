<?php

namespace App\Services\GitDeploy;

/** Where a version comes from: the commit, its branch and who pushed it. */
final class CommitMeta
{
    public function __construct(
        public readonly string $provider,       // runr | github | gitlab | forgejo
        public readonly string $sha,
        public readonly ?string $branch = null,
        public readonly ?string $authorName = null,
        public readonly ?string $authorEmail = null,
        public readonly ?string $message = null,
        public readonly ?string $url = null,
        public readonly ?int $prNumber = null,
        public readonly ?string $pushedBy = null, // user id (RunR remote) or null
    ) {}

    public function subject(): string
    {
        $subject = trim(strtok((string) $this->message, "\n") ?: '');

        return $subject !== '' ? $subject : 'git '.substr($this->sha, 0, 7);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['provider'],
            $data['sha'],
            $data['branch'] ?? null,
            $data['authorName'] ?? null,
            $data['authorEmail'] ?? null,
            $data['message'] ?? null,
            $data['url'] ?? null,
            isset($data['prNumber']) ? (int) $data['prNumber'] : null,
            $data['pushedBy'] ?? null,
        );
    }
}
