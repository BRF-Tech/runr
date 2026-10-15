<?php

namespace App\Services\GitDeploy;

use App\Models\Git\ProjectGitSettings;
use App\Models\Project;
use App\Models\Version;
use App\Services\GitService;
use Illuminate\Support\Facades\Log;

/**
 * The project's own bare repository behind its git remote
 * (<storage>/git-remotes/<organization>/<project id>.git).
 *
 * The internal repository (GitService) holds every project of an organization in one working
 * tree; the remote keeps the history users push and pull. A push to the deploy branch is
 * imported into the internal repository as a version (VersionImporter); a version saved in the
 * browser editor is committed on top of the deploy branch here (MirrorEditorVersionJob), so
 * `git pull` brings it down and the next push is a fast-forward. Preview commits of
 * pull/merge requests are stored under refs/runr/previews/<number>.
 */
class ProjectRemote
{
    public const ZERO = '0000000000000000000000000000000000000000';

    /** Hook files written into every bare repository (hooks read RUNR_* from the environment) */
    private const HOOKS = ['pre-receive', 'post-receive'];

    public function __construct(private GitRunner $git) {}

    public function baseDir(): string
    {
        return rtrim((string) (config('git-deploy.storage_path') ?: storage_path('git-remotes')), '/\\');
    }

    /** Directory of the organization's bare repositories (GIT_PROJECT_ROOT of http-backend). */
    public function organizationDir(?string $tenantSlug = null): string
    {
        $slug = $tenantSlug ?? TenantContext::currentSlug();

        return $this->baseDir().'/'.($slug !== null && $slug !== '' ? $slug : '_');
    }

    public function repoName(Project $project): string
    {
        return $project->id.'.git';
    }

    public function path(Project $project): string
    {
        return $this->organizationDir().'/'.$this->repoName($project);
    }

    public function exists(Project $project): bool
    {
        return is_file($this->path($project).'/HEAD');
    }

    /**
     * Create the bare repository when missing (HEAD on the deploy branch, RunR hooks) and seed it
     * with the project's latest version, so a clone gets the current code.
     */
    public function ensure(Project $project, ProjectGitSettings $settings): string
    {
        $path = $this->path($project);

        if (! $this->exists($project)) {
            if (! is_dir($path)) {
                mkdir($path, 0775, true);
            }
            $this->git->run(['init', '--bare', '--quiet', $path]);
            $this->git->run(['config', 'core.logAllRefUpdates', 'true'], $path);
            $this->git->run(['config', 'receive.fsckObjects', 'true'], $path);
            $this->setHead($project, $settings->deploy_branch);
            $this->writeHooks($path);
            $this->seed($project, $settings);
        } else {
            $this->writeHooks($path);
        }

        return $path;
    }

    /** Point HEAD at the deploy branch (clone checks it out). */
    public function setHead(Project $project, string $branch): void
    {
        if ($this->exists($project)) {
            $this->git->run(['symbolic-ref', 'HEAD', 'refs/heads/'.$branch], $this->path($project));
        }
    }

    /** SHA of a ref (refs/heads/main), null when it does not exist. */
    public function resolve(Project $project, string $ref): ?string
    {
        if (! $this->exists($project)) {
            return null;
        }

        [$code, $out] = $this->git->attempt(['rev-parse', '--verify', '--quiet', $ref.'^{commit}'], $this->path($project));

        return $code === 0 ? trim($out) : null;
    }

    /**
     * Branch heads of the repository.
     *
     * @return array<string, string> refs/heads/<name> → sha
     */
    public function heads(Project $project): array
    {
        if (! $this->exists($project)) {
            return [];
        }

        $out = $this->git->run(['for-each-ref', '--format=%(refname) %(objectname)', 'refs/heads'], $this->path($project));
        $refs = [];

        foreach (preg_split('/\r?\n/', trim($out)) ?: [] as $line) {
            if ($line !== '' && str_contains($line, ' ')) {
                [$ref, $sha] = explode(' ', $line, 2);
                $refs[$ref] = $sha;
            }
        }

        return $refs;
    }

    /**
     * Author and message of a commit.
     *
     * @return array{sha: string, author_name: string, author_email: string, subject: string, message: string}
     */
    public function commit(Project $project, string $sha): array
    {
        $out = $this->git->run(['show', '-s', '--format=%H%x00%an%x00%ae%x00%s%x00%B', $sha], $this->path($project));
        $parts = explode("\0", rtrim($out, "\n"), 5) + ['', '', '', '', ''];

        return [
            'sha' => trim($parts[0]),
            'author_name' => $parts[1],
            'author_email' => $parts[2],
            'subject' => $parts[3],
            'message' => trim($parts[4]),
        ];
    }

    /**
     * The function's files of a commit (SourceTree rules), read with cat-file batches: the
     * runr.yaml / runr.yml and .runrignore first, then only the blobs no ignore pattern drops.
     * Symlinks and submodules are listed as skipped.
     *
     * @throws ImportException the remaining blobs are far above the code limit of a version
     */
    public function tree(Project $project, string $sha, string $rootDir = ''): SourceTree
    {
        $path = $this->path($project);
        $rootDir = trim($rootDir, '/');
        $prefix = $rootDir === '' ? '' : $rootDir.'/';

        $args = ['ls-tree', '-r', '-z', '-l', '--full-tree', $sha];
        if ($rootDir !== '') {
            // The root directory plus the repository-root project file
            $args = [...$args, '--', $rootDir, SourceTree::RUNR_YAML, SourceTree::RUNR_YML];
        }

        $entries = [];
        $skipped = [];
        foreach (explode("\0", $this->git->run($args, $path)) as $entry) {
            if ($entry === '' || ! str_contains($entry, "\t")) {
                continue;
            }
            [$meta, $file] = explode("\t", $entry, 2);
            [$mode, $type, $object, $size] = (preg_split('/\s+/', trim($meta)) ?: []) + ['', '', '', '-'];

            if ($type !== 'blob') {
                $skipped[] = ['path' => $file, 'reason' => 'submodule'];
            } elseif ($mode === '120000') {
                $skipped[] = ['path' => $file, 'reason' => 'symlink'];
            } else {
                $entries[$file] = ['object' => $object, 'size' => (int) $size];
            }
        }

        // The project file and the ignore file decide what else is read
        $control = array_values(array_unique(array_filter([
            $prefix.SourceTree::RUNR_YAML, $prefix.SourceTree::RUNR_YML, $prefix.SourceTree::RUNR_IGNORE,
            SourceTree::RUNR_YAML, SourceTree::RUNR_YML,
        ], fn ($f) => isset($entries[$f]))));
        $controlContents = $this->catBlobs($path, array_map(fn ($f) => $entries[$f]['object'], $control));

        $files = [];
        foreach ($control as $file) {
            $files[$file] = $controlContents[$entries[$file]['object']] ?? '';
        }

        $patterns = SourceTree::patterns(SourceTree::runrYamlOf($files, $rootDir), $files[$prefix.SourceTree::RUNR_IGNORE] ?? null);
        $ceiling = SourceTree::MAX_CODE_BYTES * 8;
        $wanted = [];
        $bytes = 0;

        foreach ($entries as $file => $entry) {
            if (array_key_exists($file, $files) || ($prefix !== '' && ! str_starts_with($file, $prefix))) {
                continue;
            }
            $rel = substr($file, strlen($prefix));
            if (SourceTree::ignored($rel, $patterns)) {
                continue;
            }
            if (SourceTree::isHidden($rel)) {
                $skipped[] = ['path' => $file, 'reason' => 'hidden'];

                continue;
            }
            $bytes += $entry['size'];
            $wanted[$entry['object']][] = $file;
        }

        if ($bytes > $ceiling) {
            throw new ImportException(sprintf('The pushed tree holds %s of code files; a version holds at most %d bytes', self::humanBytes($bytes), SourceTree::MAX_CODE_BYTES));
        }

        $contents = $this->catBlobs($path, array_keys($wanted));
        foreach ($wanted as $object => $paths) {
            foreach ($paths as $file) {
                $files[$file] = $contents[$object] ?? '';
            }
        }

        return SourceTree::fromRepository($files, $rootDir, $skipped);
    }

    /**
     * Commit $files (paths relative to $rootDir) onto $ref with compare-and-swap.
     *
     * $replace null: the commit holds exactly $files (preview commits, the first commit).
     * $replace a list: start from the parent's tree, remove those paths (relative to the root
     * directory) and write $files — the editor mirror, which leaves everything that is not a
     * function file (an xaas project's README, runr.yaml, ignored and hidden files, other
     * directories of a monorepo) as it was.
     *
     * @param  array<string, string>  $files
     * @param  array{name: string, email: string}|null  $author
     * @param  list<string>|null  $replace
     * @return string|null the new commit, null when the tree did not change
     *
     * @throws GitCommandException the ref moved in between (update-ref compare failed) or git failed
     */
    public function commitFiles(Project $project, string $ref, array $files, string $message, ?array $author = null, string $rootDir = '', ?array $replace = null): ?string
    {
        $path = $this->path($project);
        $rootDir = trim($rootDir, '/');
        $prefix = $rootDir === '' ? '' : $rootDir.'/';
        $parent = $this->resolve($project, $ref);
        $index = tempnam(sys_get_temp_dir(), 'runr-idx');
        $blobDir = sys_get_temp_dir().'/runr-blobs-'.bin2hex(random_bytes(6));
        $env = ['GIT_INDEX_FILE' => $index];

        try {
            @unlink($index);

            if ($parent && $replace !== null) {
                $this->git->run(['read-tree', $parent], $path, $env);

                $existing = array_flip(array_filter(explode("\0", $this->git->run(['ls-files', '-z'], $path, $env))));
                $remove = array_values(array_filter(
                    array_map(fn ($rel) => $prefix.ltrim((string) $rel, '/'), $replace),
                    fn ($file) => isset($existing[$file]),
                ));

                // Mode 0 in --index-info removes an entry; --force-remove needs a work tree, which
                // the bare remote has none of ("this operation must be run in a work tree")
                if ($remove) {
                    $this->git->run(['update-index', '--index-info'], $path, $env, implode('', array_map(
                        fn (string $file) => '0 '.str_repeat('0', 40)."\t".$file."\n",
                        $remove,
                    )));
                }
            } else {
                $this->git->run(['read-tree', '--empty'], $path, $env);
            }

            if ($files) {
                mkdir($blobDir, 0700, true);
                $names = [];
                $i = 0;
                foreach ($files as $file => $content) {
                    $tmp = $blobDir.'/'.$i++;
                    file_put_contents($tmp, $content);
                    $names[$tmp] = $prefix.ltrim((string) $file, '/');
                }

                $shas = preg_split('/\r?\n/', trim($this->git->run(['hash-object', '-w', '--stdin-paths'], $path, [], implode("\n", array_keys($names))."\n"))) ?: [];
                $info = '';
                foreach (array_values($names) as $n => $file) {
                    $info .= '100644 '.trim($shas[$n] ?? '')."\t".$file."\n";
                }
                $this->git->run(['update-index', '--add', '--index-info'], $path, $env, $info);
            }

            $tree = trim($this->git->run(['write-tree'], $path, $env));

            if ($parent && trim($this->git->run(['rev-parse', $parent.'^{tree}'], $path)) === $tree) {
                return null;
            }

            $identity = [
                'GIT_AUTHOR_NAME' => $author['name'] ?? 'RunR',
                'GIT_AUTHOR_EMAIL' => $author['email'] ?? 'runr@localhost',
                'GIT_COMMITTER_NAME' => 'RunR',
                'GIT_COMMITTER_EMAIL' => 'runr@localhost',
            ];
            $args = ['commit-tree', $tree, ...($parent ? ['-p', $parent] : []), '-F', '-'];
            $commit = trim($this->git->run($args, $path, $identity, $message));

            $this->git->run(['update-ref', '-m', 'runr: '.strtok($message, "\n"), $ref, $commit, $parent ?? self::ZERO], $path);

            return $commit;
        } finally {
            @unlink($index);
            if (is_dir($blobDir)) {
                foreach (glob($blobDir.'/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($blobDir);
            }
        }
    }

    public function deleteRef(Project $project, string $ref): void
    {
        if ($this->exists($project)) {
            $this->git->attempt(['update-ref', '-d', $ref], $this->path($project));
        }
    }

    /** Remove the bare repository (project deleted). Only ever a path below the remotes directory. */
    public function delete(Project $project): void
    {
        $path = $this->path($project);
        $base = realpath($this->baseDir());
        $real = realpath($path);

        if (! $base || ! $real || ! str_starts_with($real, $base.DIRECTORY_SEPARATOR) || ! str_ends_with($real, '.git')) {
            return;
        }

        self::removeTree($real);
    }

    /**
     * Seed an empty remote with the latest version's files (one commit on the deploy branch).
     */
    private function seed(Project $project, ProjectGitSettings $settings): void
    {
        $version = Version::where('project_id', $project->id)->whereNotNull('commit_id')->orderByDesc('number')->first();
        if (! $version) {
            return;
        }

        try {
            $files = array_filter(
                app(GitService::class)->getAllFilesAtVersion($project, $version->commit_id),
                fn ($content, $file) => SourceTree::isManaged((string) $file),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($files) {
                $this->commitFiles(
                    $project,
                    $settings->deployRef(),
                    $files,
                    "Import {$project->name} {$version->label} from RunR",
                    null,
                    $settings->rootDir(),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Git remote seed failed', ['project' => $project->id, 'error' => $e->getMessage()]);
        }
    }

    private function writeHooks(string $repoPath): void
    {
        $dir = $repoPath.'/hooks';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        foreach (self::HOOKS as $hook) {
            $source = resource_path("git-hooks/{$hook}");
            $target = "{$dir}/{$hook}";
            $content = (string) @file_get_contents($source);

            if ($content !== '' && (! is_file($target) || file_get_contents($target) !== $content)) {
                file_put_contents($target, $content);
                @chmod($target, 0755);
            }
        }
    }

    /**
     * @param  list<string>  $objects
     * @return array<string, string> object → content
     */
    private function catBlobs(string $path, array $objects): array
    {
        if (! $objects) {
            return [];
        }

        $out = $this->git->run(['cat-file', '--batch'], $path, [], implode("\n", $objects)."\n", 300);
        $result = [];
        $offset = 0;
        $length = strlen($out);

        while ($offset < $length) {
            $eol = strpos($out, "\n", $offset);
            if ($eol === false) {
                break;
            }
            $header = substr($out, $offset, $eol - $offset);
            $offset = $eol + 1;
            $parts = explode(' ', $header);

            if (count($parts) < 3) {
                continue; // "<object> missing"
            }

            [$object, , $size] = $parts;
            $result[$object] = substr($out, $offset, (int) $size);
            $offset += (int) $size + 1;
        }

        return $result;
    }

    public static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MiB' : round($bytes / 1024, 1).' KiB';
    }

    private static function removeTree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
