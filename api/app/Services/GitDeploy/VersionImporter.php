<?php

namespace App\Services\GitDeploy;

use App\Models\Git\ProjectGitSettings;
use App\Models\Git\VersionGitSource;
use App\Models\Project;
use App\Models\Version;
use App\Services\GitService;
use App\Services\ProjectService;
use App\Services\Runtimes\ProjectEntrypoint;
use App\Services\Runtimes\RuntimeRegistry;

/**
 * Turns a pushed tree into a version of the project — through the same ProjectService calls
 * the browser editor uses (commit into the internal repository, build when the runtime needs
 * one), after applying the tree's runr.yaml:
 *  - faas: every file of the root directory; the handler file must exist and hold the handler;
 *  - xaas: the handler file only (a single-file project), other files are listed as skipped;
 *  - caas: no code — the runr.yaml (image, env, scale, ...) becomes a config version.
 * The version records its commit (VersionGitSource). A tree identical to the latest version
 * creates nothing (the result says so).
 */
class VersionImporter
{
    /** True while an import commits: the editor mirror leaves these versions alone. */
    public static bool $importing = false;

    public function __construct(
        private ProjectService $projects,
        private GitService $git,
        private RuntimeRegistry $runtimes,
        private RunrYamlApplier $applier,
    ) {}

    /**
     * @return array{version: ?Version, created: bool, warnings: list<string>, applied: array<string, mixed>, skipped: list<array{path: string, reason: string}>}
     *
     * @throws ImportException
     */
    public function import(Project $project, SourceTree $tree, CommitMeta $meta): array
    {
        $project->loadMissing(['xaasConfig', 'faasConfig', 'containerConfig', 'knativeRegion']);

        if (! $project->isSupportedType()) {
            throw new ImportException("Projects of type {$project->service_type} cannot be changed");
        }
        if ($project->isRuntimeEol()) {
            throw new ImportException(__('api.project.eol_save'));
        }

        $parsed = RunrYaml::parse($tree->runrYaml);
        $result = $this->applier->apply($project, $parsed['settings']);
        $warnings = array_merge($parsed['warnings'], $result['warnings']);
        $skipped = $tree->skipped;

        if ($parsed['settings']) {
            ProjectGitSettings::for($project)->update(['runr_yaml' => $parsed['settings']]);
        }

        $project->refresh()->load(['xaasConfig', 'faasConfig', 'containerConfig', 'knativeRegion']);
        $before = Version::where('project_id', $project->id)->max('number');
        $message = mb_substr($meta->subject(), 0, 240);
        $userId = $meta->pushedBy ?? 'git';

        $version = $this->locked(function () use ($project, $tree, $message, $userId, $result, &$skipped) {
            return match ($project->service_type) {
                'caas' => $this->importContainer($project, $message, $userId, $result['applied']),
                'xaas' => $this->importSnippet($project, $tree, $message, $userId, $skipped),
                'faas' => $this->importFunction($project, $tree, $message, $userId),
            };
        });

        $created = $version !== null && (int) $version->number > (int) ($before ?? 0);

        if ($created) {
            VersionGitSource::create([
                'version_id' => $version->id,
                'project_id' => $project->id,
                'provider' => $meta->provider,
                'sha' => $meta->sha,
                'ref' => $meta->branch,
                'author_name' => $meta->authorName ? mb_substr($meta->authorName, 0, 250) : null,
                'author_email' => $meta->authorEmail ? mb_substr($meta->authorEmail, 0, 250) : null,
                'message' => $meta->message,
                'url' => $meta->url,
                'pr_number' => $meta->prNumber,
                'pushed_by' => $meta->pushedBy,
            ]);
        }

        return [
            'version' => $version,
            'created' => $created,
            'warnings' => $warnings,
            'applied' => $result['applied'],
            'skipped' => $skipped,
        ];
    }

    /** The handler file of a code project (ProjectEntrypoint). */
    public function mainFile(Project $project): string
    {
        $manifest = $this->runtimes->manifest($project->runtime ?? 'python');

        return $manifest ? ProjectEntrypoint::for($project, $manifest)->filename : 'main.txt';
    }

    /** @throws ImportException the handler file does not hold the handler function */
    public function assertHandler(Project $project, string $code): void
    {
        $manifest = $this->runtimes->manifest($project->runtime);
        $pattern = $manifest?->handlerPattern(ProjectEntrypoint::for($project, $manifest)->handler);

        if ($pattern && ! preg_match($pattern, $code)) {
            throw new ImportException(__('api.code.handler_required'));
        }
    }

    private function importFunction(Project $project, SourceTree $tree, string $message, string $userId): Version
    {
        $files = $this->functionFiles($project, $tree);

        return $this->withImportFlag(fn () => $this->projects->saveFaaSFiles($project, $files, $userId, $message));
    }

    /** @param list<array{path: string, reason: string}> $skipped */
    private function importSnippet(Project $project, SourceTree $tree, string $message, string $userId, array &$skipped): Version
    {
        $files = $this->functionFiles($project, $tree, $skipped);
        $main = array_key_first($files);

        return $this->withImportFlag(fn () => $this->projects->saveXaaSCode($project, $files[$main], $userId, $message));
    }

    /**
     * The files a code project's version holds — faas: the whole flat set (the CLI's rules,
     * SourceTree::assertFunctionFiles); xaas: its one script (other files are listed as skipped).
     * The handler file must exist and hold the handler.
     *
     * @param  list<array{path: string, reason: string}>  $skipped
     * @return array<string, string>
     *
     * @throws ImportException
     */
    public function functionFiles(Project $project, SourceTree $tree, array &$skipped = []): array
    {
        if ($project->service_type === 'xaas') {
            $main = $project->xaasConfig?->filename ?: $this->mainFile($project);
            if (! isset($tree->files[$main])) {
                throw new ImportException("The script {$main} is not in the tree".$this->rootHint($project));
            }
            $tree->assertText($main, $tree->files[$main]);
            $files = [$main => $tree->files[$main]];
            (new SourceTree($files))->assertWithinLimits();

            foreach (array_keys($tree->files) as $file) {
                if ($file !== $main) {
                    $skipped[] = ['path' => (string) $file, 'reason' => 'single-file project'];
                }
            }
        } else {
            $tree->assertFunctionFiles();
            $files = $tree->files;
            $main = $this->mainFile($project);
            if (! isset($files[$main])) {
                throw new ImportException("The handler file {$main} is not in the tree".$this->rootHint($project));
            }
        }

        $this->assertHandler($project, $files[$main]);

        return $files;
    }

    private function importContainer(Project $project, string $message, string $userId, array $applied): ?Version
    {
        if (! $applied) {
            return Version::where('project_id', $project->id)->orderByDesc('number')->first();
        }

        return $this->withImportFlag(fn () => $this->projects->updateCaaSConfig($project, [], $userId, $message));
    }

    private function rootHint(Project $project): string
    {
        $root = ProjectGitSettings::for($project)->rootDir();

        return $root !== '' ? " (root directory {$root}/)" : '';
    }

    /**
     * One commit at a time in the organization's internal repository (shared working tree).
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function locked(\Closure $callback): mixed
    {
        // GitService's own lock (re-entrant: the commit inside takes it again)
        return $this->git->exclusive(function () use ($callback) {
            $this->git->initRepo(); // idempotent: the organization's repository exists from here on

            return $callback();
        });
    }

    private function withImportFlag(\Closure $callback): mixed
    {
        $previous = self::$importing;
        self::$importing = true;

        try {
            return $callback();
        } finally {
            self::$importing = $previous;
        }
    }
}
