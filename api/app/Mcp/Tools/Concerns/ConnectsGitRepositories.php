<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Tools\Schema;

/**
 * Credential arguments of the repository tools (POST /projects/{id}/git/repos | connect): a
 * GitHub App installation, a personal / project access token (GitLab, Forgejo, Gitea) or the
 * reference of a GitLab sign-in made in the console.
 */
trait ConnectsGitRepositories
{
    /** @return array<string, array> schema properties */
    protected static function credentialProperties(): array
    {
        return [
            'provider' => Schema::string('Git provider by id, type (github, gitlab, forgejo) or name; optional when only one is set up (git_status lists them).', ['maxLength' => 100]),
            'installation_id' => Schema::string('GitHub: the GitHub App installation id of the organization.', ['maxLength' => 64]),
            'access_token' => Schema::string('GitLab / Forgejo / Gitea: an access token that can read the repository (stored encrypted, never shown again).', ['maxLength' => 500]),
            'token_ref' => Schema::string('GitLab: the reference of a sign-in made on the Git tab.', ['maxLength' => 100]),
        ];
    }

    /** The credential part of the request body, provider resolved to its id. */
    protected static function credentialBody(array $arguments, string $providerId): array
    {
        return array_filter([
            'provider_id' => $providerId,
            'installation_id' => $arguments['installation_id'] ?? null,
            'access_token' => $arguments['access_token'] ?? null,
            'token_ref' => $arguments['token_ref'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
