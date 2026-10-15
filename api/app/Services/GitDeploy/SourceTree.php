<?php

namespace App\Services\GitDeploy;

/**
 * The files of a commit that become a version, chosen like `runr deploy` chooses them
 * (cli/internal/projectfile/files.go) so a push and the CLI create the same version:
 *  - the project's root directory (monorepo), paths relative to it;
 *  - never: DEFAULT_IGNORE (.git, node_modules, vendor, __pycache__, *.pyc, .venv, venv, .env,
 *    .env.*, .gitignore, .runrignore, runr.yaml, runr.yml, …), the patterns of `ignore:` in the
 *    runr.yaml and of a .runrignore file in the root directory (a pattern without "/" matches
 *    any path element, with "/" the whole path);
 *  - other hidden paths (a segment starting with ".") are listed as skipped: RunR keeps its own
 *    files there;
 *  - a function (faas) is a flat set of UTF-8 text files, none empty, names + contents at most
 *    MAX_CODE_BYTES (each file is a ConfigMap key): assertFunctionFiles() says what is wrong.
 * The runr.yaml (or runr.yml) is read from the root directory, else from the repository root.
 */
final class SourceTree
{
    public const RUNR_YAML = 'runr.yaml';

    public const RUNR_YML = 'runr.yml';

    public const RUNR_IGNORE = '.runrignore';

    /** The API's limit for one version's code (names + contents), like the CLI's runr.MaxCodeBytes */
    public const MAX_CODE_BYTES = 1_000_000;

    public const DEFAULT_IGNORE = [
        '.git', '.hg', '.svn', '.runr', '.idea', '.vscode', '.DS_Store', 'Thumbs.db',
        'node_modules', 'vendor', '__pycache__', '*.pyc', '.venv', 'venv',
        '.env', '.env.*', '.gitignore', self::RUNR_IGNORE, self::RUNR_YAML, self::RUNR_YML,
    ];

    /**
     * @param  array<string, string>  $files  path (relative to the root directory) → content
     * @param  list<array{path: string, reason: string}>  $skipped
     */
    public function __construct(
        public readonly array $files,
        public readonly array $skipped = [],
        public readonly ?string $runrYaml = null,
    ) {}

    public function totalBytes(): int
    {
        $total = 0;
        foreach ($this->files as $name => $content) {
            $total += strlen((string) $name) + strlen($content);
        }

        return $total;
    }

    /**
     * A function's files must be a flat set of non-empty UTF-8 text files within the size limit.
     *
     * @throws ImportException
     */
    public function assertFunctionFiles(): void
    {
        $nested = array_values(array_filter(array_keys($this->files), fn ($f) => str_contains((string) $f, '/')));
        if ($nested) {
            sort($nested);
            throw new ImportException('Functions hold a flat set of files; move these to the root directory or add them to ignore in runr.yaml: '.implode(', ', array_slice($nested, 0, 20)).(count($nested) > 20 ? ', …' : ''));
        }

        foreach ($this->files as $name => $content) {
            $this->assertText((string) $name, $content);
        }

        $this->assertWithinLimits();
    }

    /** @throws ImportException the file is binary or empty */
    public function assertText(string $name, string $content): void
    {
        if (! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw new ImportException("{$name} is not UTF-8 text; RunR stores code as text (add it to ignore in runr.yaml)");
        }
        if ($content === '') {
            throw new ImportException("{$name} is empty; RunR does not store empty files (remove it or add it to ignore in runr.yaml)");
        }
    }

    /** @throws ImportException more files or bytes than a version can hold */
    public function assertWithinLimits(): void
    {
        $maxBytes = min(self::MAX_CODE_BYTES, (int) config('git-deploy.max_import_bytes', self::MAX_CODE_BYTES));
        $maxFiles = (int) config('git-deploy.max_import_files', 1000);

        if (count($this->files) > $maxFiles) {
            throw new ImportException(sprintf('The tree has %d files; at most %d can be imported', count($this->files), $maxFiles));
        }
        if (($total = $this->totalBytes()) > $maxBytes) {
            throw new ImportException(sprintf('The code is %d bytes; the limit is %d bytes (names + contents)', $total, $maxBytes));
        }
    }

    /**
     * Build a tree from the files of a repository (paths relative to the repository root).
     *
     * @param  array<string, string>  $repoFiles
     * @param  list<array{path: string, reason: string}>  $skipped  entries skipped earlier (symlinks, submodules)
     */
    public static function fromRepository(array $repoFiles, string $rootDir = '', array $skipped = []): self
    {
        $prefix = self::prefix($rootDir);
        $yaml = self::runrYamlOf($repoFiles, $rootDir);
        $patterns = self::patterns($yaml, $repoFiles[$prefix.self::RUNR_IGNORE] ?? null);
        $files = [];

        foreach ($repoFiles as $path => $content) {
            $path = ltrim((string) $path, '/');
            if ($prefix !== '' && ! str_starts_with($path, $prefix)) {
                continue;
            }

            $rel = substr($path, strlen($prefix));
            if ($rel === '' || self::ignored($rel, $patterns)) {
                continue;
            }

            if (self::isHidden($rel)) {
                $skipped[] = ['path' => $path, 'reason' => 'hidden'];

                continue;
            }

            $files[$rel] = $content;
        }

        ksort($files);

        return new self($files, $skipped, $yaml);
    }

    /** The runr.yaml (or runr.yml) of the root directory, else of the repository root. */
    public static function runrYamlOf(array $repoFiles, string $rootDir = ''): ?string
    {
        $prefix = self::prefix($rootDir);

        foreach (array_unique([$prefix, '']) as $dir) {
            foreach ([self::RUNR_YAML, self::RUNR_YML] as $name) {
                if (isset($repoFiles[$dir.$name])) {
                    return $repoFiles[$dir.$name];
                }
            }
        }

        return null;
    }

    /**
     * Ignore patterns: the defaults, runr.yaml `ignore:` and the lines of .runrignore.
     *
     * @return list<string>
     */
    public static function patterns(?string $runrYaml, ?string $runrIgnore = null): array
    {
        $patterns = self::DEFAULT_IGNORE;

        foreach (RunrYaml::ignorePatterns($runrYaml) as $pattern) {
            $patterns[] = $pattern;
        }

        foreach (preg_split('/\r?\n/', (string) $runrIgnore) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                $patterns[] = $line;
            }
        }

        return $patterns;
    }

    /**
     * The CLI's rule: a pattern without "/" matches any path element; with "/" it matches the
     * whole path or a directory prefix of it.
     *
     * @param  list<string>  $patterns
     */
    public static function ignored(string $rel, array $patterns): bool
    {
        $parts = explode('/', $rel);

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '' || str_starts_with($pattern, '#')) {
                continue;
            }
            $pattern = trim($pattern, '/');

            if (str_contains($pattern, '/')) {
                if (fnmatch($pattern, $rel, FNM_PATHNAME) || str_starts_with($rel, $pattern.'/')) {
                    return true;
                }

                continue;
            }

            foreach ($parts as $element) {
                if (fnmatch($pattern, $element)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A segment starting with "." (or an unsafe path). */
    public static function isHidden(string $rel): bool
    {
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '..' || str_starts_with($segment, '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a file of the internal repository belongs to the function's files (the editor
     * mirror and the remote seed copy only those).
     */
    public static function isManaged(string $rel): bool
    {
        return $rel !== '' && ! self::isHidden($rel) && ! self::ignored($rel, self::DEFAULT_IGNORE);
    }

    private static function prefix(string $rootDir): string
    {
        $rootDir = trim($rootDir, '/');

        return $rootDir === '' ? '' : $rootDir.'/';
    }
}
