<?php

namespace App\Services\GitDeploy\Providers;

use App\Models\Git\GitProvider;
use InvalidArgumentException;

/** The REST client of a provider type. */
class ProviderClients
{
    public function for(string $type): ProviderClient
    {
        return match ($type) {
            GitProvider::GITHUB => app(GitHubClient::class),
            GitProvider::GITLAB => app(GitLabClient::class),
            GitProvider::FORGEJO => app(ForgejoClient::class),
            default => throw new InvalidArgumentException("Unknown git provider type {$type}"),
        };
    }
}
