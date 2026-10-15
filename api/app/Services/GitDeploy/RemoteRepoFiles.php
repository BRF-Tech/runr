<?php

namespace App\Services\GitDeploy;

use App\Models\Project;
use App\Services\GitService;

/**
 * GitService that reads a version's files from the project's own bare repository instead of
 * the organization's internal one: preview versions point at commits under
 * refs/runr/previews/<number> there. Handed to FunctionBuildService (preview builds) and
 * KnativeDeployService::buildManifests (preview ConfigMap) — both only read files.
 */
class RemoteRepoFiles extends GitService
{
    /** @var array<string, array<string, string>> commit → files */
    private array $cache = [];

    public function __construct(private ProjectRemote $remote) {}

    public function getAllFilesAtVersion(Project $project, string $commitId): array
    {
        return $this->cache[$commitId] ??= $this->remote->tree($project, $commitId)->files;
    }

    public function listFilesAtVersion(Project $project, string $commitId): array
    {
        return array_keys($this->getAllFilesAtVersion($project, $commitId));
    }

    public function getFileAtVersion(Project $project, string $commitId, string $filename): ?string
    {
        return $this->getAllFilesAtVersion($project, $commitId)[$filename] ?? null;
    }
}
