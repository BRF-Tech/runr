<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Version;
use App\Services\GitDeploy\GitRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The internal repository of an organization: every project's code in one working tree at
 * repoPath() (storage/git; storage/git/<tenant slug> with tenancy, TenantAwareGitService).
 *
 * Git only ever runs in that repository: GIT_DIR and GIT_WORK_TREE name it, so git never looks
 * for a repository in the parent directories (a source checkout around storage/ stays
 * untouched), the write paths create it on first use, and commits carry RunR's identity rather
 * than a global git identity (GitRunner keeps user and system git config out).
 */
class GitService
{
    private const AUTHOR_NAME = 'RunR System';

    private const AUTHOR_EMAIL = 'runr@system';

    /** Repository locks this process holds (exclusive() is re-entrant within one process). */
    private static array $held = [];

    /**
     * Runs a write to the repository on its own: every project of an organization shares one
     * working tree and index, and Octane workers, Horizon jobs and the scheduler write to it at
     * the same time — two concurrent `git add`s meant "index.lock: File exists" and a 500.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function exclusive(\Closure $callback): mixed
    {
        $key = 'runr-git-internal:'.md5($this->repoPath());
        if (isset(self::$held[$key])) {
            return $callback();
        }

        return Cache::lock($key, 120)->block(90, function () use ($key, $callback) {
            self::$held[$key] = true;
            try {
                return $callback();
            } finally {
                unset(self::$held[$key]);
            }
        });
    }

    /** Create the repository (with a first commit) unless it exists; idempotent. */
    public function initRepo(): void
    {
        $path = $this->repoPath();

        if (is_dir($path.'/.git')) {
            return;
        }

        $this->exclusive(function () use ($path) {
            if (is_dir($path.'/.git')) {
                return;
            }

            $this->ensureDir($path);
            $this->run(['init'], $path);
            // For git run by hand in the repository; RunR's own commits set the identity (run())
            $this->run(['config', 'user.email', self::AUTHOR_EMAIL], $path);
            $this->run(['config', 'user.name', self::AUTHOR_NAME], $path);

            file_put_contents($path.'/.gitkeep', '');
            $this->run(['add', '.gitkeep'], $path);
            $this->run(['commit', '-m', 'init: repository created'], $path);
        });
    }

    /**
     * Write files to git and commit; returns the commit SHA.
     * Does NOT create a Version record — caller is responsible.
     */
    public function writeFiles(Project $project, array $files, string $message): string
    {
        return $this->exclusive(fn () => $this->writeFilesNow($project, $files, $message));
    }

    public function commitFiles(Project $project, array $files, string $message, string $userId, ?string $buildStatus = 'ready'): Version
    {
        return $this->exclusive(fn () => $this->commitFilesNow($project, $files, $message, $userId, $buildStatus));
    }

    private function writeFilesNow(Project $project, array $files, string $message): string
    {
        $this->initRepo();
        $repoPath = $this->repoPath();
        $projectDir = $this->projectDir($project);

        foreach ($files as $filename => $content) {
            $relPath = $projectDir.'/'.$filename;
            $filePath = $repoPath.'/'.$relPath;
            $this->ensureDir(dirname($filePath));
            file_put_contents($filePath, $content);
            $this->run(['add', $relPath], $repoPath);
        }

        // No staged changes: no commit needed
        $staged = trim($this->run(['diff', '--cached', '--name-only'], $repoPath));
        if (! empty($staged)) {
            $this->run(['commit', '-m', $message], $repoPath);
        }

        return $this->getLatestCommitId($repoPath);
    }

    private function commitFilesNow(Project $project, array $files, string $message, string $userId, ?string $buildStatus): Version
    {
        $this->initRepo();
        $repoPath = $this->repoPath();
        $projectDir = $this->projectDir($project);

        // Detect removed files: existing on disk but not in incoming set
        $projectPath = $repoPath.'/'.$projectDir;
        $removedFiles = [];
        if (is_dir($projectPath)) {
            $existing = $this->listDiskFiles($projectPath);
            foreach ($existing as $existingFile) {
                if (! array_key_exists($existingFile, $files)) {
                    $relPath = $projectDir.'/'.$existingFile;
                    $this->run(['rm', '-f', $relPath], $repoPath);
                    $removedFiles[] = $existingFile;
                }
            }
        }

        // Write and stage incoming files
        $changedFiles = [];
        foreach ($files as $filename => $content) {
            $relPath = $projectDir.'/'.$filename;
            $filePath = $repoPath.'/'.$relPath;
            $this->ensureDir(dirname($filePath));
            file_put_contents($filePath, $content);
            $this->run(['add', $relPath], $repoPath);
            $changedFiles[] = $filename;
        }

        // Only commit if there are staged changes
        $staged = trim($this->run(['diff', '--cached', '--name-only'], $repoPath));
        if (empty($staged)) {
            // Nothing changed — return the latest existing version
            $latest = Version::where('project_id', $project->id)->orderByDesc('number')->first();
            if ($latest) {
                return $latest;
            }
        }

        $this->run(['commit', '-m', $message], $repoPath);
        $commitId = $this->getLatestCommitId($repoPath);
        $lastVersion = Version::where('project_id', $project->id)->max('number') ?? 0;

        return Version::create([
            'project_id' => $project->id,
            'number' => $lastVersion + 1,
            'commit_id' => $commitId,
            'commit_message' => $message,
            'created_by' => $userId,
            'changed_files' => array_merge($changedFiles, array_map(fn ($f) => '-'.$f, $removedFiles)),
            'build_status' => $buildStatus,
        ]);
    }

    public function getFileAtVersion(Project $project, string $commitId, string $filename): ?string
    {
        $path = $this->repoPath();
        $relPath = $this->projectDir($project).'/'.$filename;

        try {
            return $this->run(['show', "{$commitId}:{$relPath}"], $path);
        } catch (\Exception $e) {
            Log::error("Git show failed: {$e->getMessage()}");

            return null;
        }
    }

    public function listFilesAtVersion(Project $project, string $commitId): array
    {
        $path = $this->repoPath();
        $projectDir = $this->projectDir($project);
        $prefix = $projectDir.'/';

        $output = $this->run(['ls-tree', '--name-only', '-r', $commitId, '--', $projectDir], $path);

        return array_values(array_filter(
            array_map(
                fn ($f) => str_starts_with($f, $prefix) ? substr($f, strlen($prefix)) : $f,
                explode("\n", trim($output))
            ),
            fn ($f) => $f !== '' && $f !== '.gitkeep'
        ));
    }

    public function getAllFilesAtVersion(Project $project, string $commitId): array
    {
        $files = $this->listFilesAtVersion($project, $commitId);
        $results = [];

        foreach ($files as $filename) {
            $content = $this->getFileAtVersion($project, $commitId, $filename);
            if ($content !== null) {
                $results[$filename] = $content;
            }
        }

        return $results;
    }

    /**
     * The project's files at $commitId as a tar written to $target (git archive of the project's
     * directory at that commit) — an organization export's copy of a version (docs/org-export.md).
     * False when the commit or the directory does not exist.
     */
    public function archiveVersion(Project $project, string $commitId, string $target): bool
    {
        $path = $this->repoPath();
        if (! is_dir($path.'/.git') || ! preg_match('/^[0-9a-f]{7,64}$/', $commitId)) {
            return false;
        }

        try {
            $this->run(['archive', '--format=tar', '-o', $target, $commitId.':'.$this->projectDir($project)], $path);

            return is_file($target);
        } catch (\Throwable $e) {
            Log::warning('Version not archived', ['project' => $project->id, 'commit' => $commitId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Make $files the project's whole directory and commit it — also when nothing changed, so an
     * imported version keeps its own commit and number (docs/org-export.md). Does NOT create a
     * Version record; returns the commit SHA.
     *
     * @param  array<string, string>  $files  relative path => content
     */
    public function commitSnapshot(Project $project, array $files, string $message): string
    {
        return $this->exclusive(function () use ($project, $files, $message) {
            $this->initRepo();
            $repoPath = $this->repoPath();
            $projectDir = $this->projectDir($project);
            $projectPath = $repoPath.'/'.$projectDir;

            // The directory holds exactly $files afterwards: what the previous version had goes
            $this->run(['rm', '-r', '-q', '--cached', '--ignore-unmatch', '--', $projectDir], $repoPath);
            if (is_dir($projectPath)) {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($projectPath, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($items as $item) {
                    /** @var \SplFileInfo $item */
                    $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
            }

            foreach ($files as $filename => $content) {
                $relPath = $projectDir.'/'.$filename;
                $filePath = $repoPath.'/'.$relPath;
                $this->ensureDir(dirname($filePath));
                file_put_contents($filePath, $content);
            }
            if ($files !== []) {
                $this->run(['add', '-A', '--', $projectDir], $repoPath);
            }
            $this->run(['commit', '--allow-empty', '-q', '-m', $message], $repoPath);

            return $this->getLatestCommitId($repoPath);
        });
    }

    public function repoPath(): string
    {
        return storage_path('git');
    }

    public function projectDir(Project $project): string
    {
        return $project->id;
    }

    private function getLatestCommitId(string $path): string
    {
        return trim($this->run(['rev-parse', 'HEAD'], $path));
    }

    /** List non-hidden files on disk (relative to given directory), excluding .gitkeep */
    private function listDiskFiles(string $dir, string $prefix = ''): array
    {
        $results = [];
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $full = $dir.'/'.$entry;
            $rel = $prefix === '' ? $entry : $prefix.'/'.$entry;
            if (is_dir($full)) {
                $results = array_merge($results, $this->listDiskFiles($full, $rel));
            } else {
                $results[] = $rel;
            }
        }

        return $results;
    }

    private function ensureDir(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Run git in the repository at $path and in no other: GIT_DIR / GIT_WORK_TREE instead of
     * repository discovery (a missing .git fails rather than reaching an enclosing repository)
     * and RunR's identity on commits.
     *
     * @param  list<string>  $args  arguments after "git"
     */
    private function run(array $args, string $path): string
    {
        $path = realpath($path) ?: $path;

        return app(GitRunner::class)->run($args, $path, [
            'GIT_DIR' => $path.DIRECTORY_SEPARATOR.'.git',
            'GIT_WORK_TREE' => $path,
            'GIT_AUTHOR_NAME' => self::AUTHOR_NAME,
            'GIT_AUTHOR_EMAIL' => self::AUTHOR_EMAIL,
            'GIT_COMMITTER_NAME' => self::AUTHOR_NAME,
            'GIT_COMMITTER_EMAIL' => self::AUTHOR_EMAIL,
        ]);
    }
}
