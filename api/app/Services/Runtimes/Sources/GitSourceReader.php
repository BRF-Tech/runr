<?php

namespace App\Services\Runtimes\Sources;

use App\Models\RuntimeSource;
use App\Services\GitDeploy\GitRunner;
use App\Services\Runtimes\InvalidManifestException;
use App\Services\Runtimes\ManifestValidator;

/**
 * Reads a Git source (docs/runtimes.md#git-sources): the manifests <path>/<id>/runtime.yaml of a
 * repository at a branch or tag.
 *
 *  1. git ls-remote: the commit of the ref. Unchanged since the last good read — nothing more to
 *     do; a check costs one ls-remote.
 *  2. A shallow clone of that ref into a temporary directory (no tags, no submodules, symlinks
 *     checked out as plain files, only the https / ssh protocols — http and git with
 *     runtime_sources.allow_insecure).
 *  3. Every <path>/<id>/runtime.yaml decoded; "@file" build files inlined from the runtime's
 *     directory; for a runtime with a dockerfile version every other file of its directory is the
 *     build context (its uploaded files). Nothing is read from outside the clone.
 *
 * Credentials never reach a command line: an HTTPS token goes in as http.extraHeader through
 * GIT_CONFIG_* variables, an SSH deploy key as a 0600 file for GIT_SSH_COMMAND, both removed when
 * the read ends; every error is masked (SourceCredentials).
 */
class GitSourceReader
{
    /** Largest manifest file read */
    private const MAX_MANIFEST_BYTES = 262144;

    /** A runtime directory's uploaded files (they reach the build as a ConfigMap) */
    private const MAX_FILES = 50;

    private const MAX_FILES_BYTES = 900 * 1024;

    public function __construct(
        private readonly GitRunner $git,
        private readonly ManifestValidator $validator,
    ) {}

    /**
     * @throws RuntimeSourceException
     */
    public function read(RuntimeSource $source, bool $force = false): SourceSnapshot
    {
        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'runr-source-'.bin2hex(random_bytes(6));
        @mkdir($work, 0700, true);

        try {
            [$env, $config] = $this->access($source, $work);

            $revision = $this->remoteRevision($source, $env, $config);
            if (! $force && $revision === $source->revision && in_array($source->status, [RuntimeSource::OK, RuntimeSource::PARTIAL], true)) {
                return SourceSnapshot::unchanged($revision, false);
            }

            $checkout = $work.DIRECTORY_SEPARATOR.'repo';
            $args = ['clone', '--depth', '1', '--no-tags', '--single-branch', '--no-recurse-submodules'];
            if (($ref = $this->branchOrTag($source->ref)) !== null) {
                array_push($args, '--branch', $ref);
            }
            array_push($args, '--', $source->url, $checkout);
            $this->run($source, $args, null, $env, $config);

            $head = trim($this->run($source, ['rev-parse', 'HEAD'], $checkout, $env, $config));

            return new SourceSnapshot($head !== '' ? $head : $revision, $this->entries($checkout, SourceLocation::path($source->path)));
        } finally {
            self::removeDirectory($work);
        }
    }

    /**
     * Environment and config of the git commands: the protocols allowed, the credentials.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function access(RuntimeSource $source, string $work): array
    {
        $config = [
            'protocol.allow' => 'never',
            'protocol.https.allow' => 'always',
            'protocol.ssh.allow' => 'always',
            'core.symlinks' => 'false',
            'core.hooksPath' => '/dev/null',
            'credential.helper' => '',
            'http.lowSpeedLimit' => '1000',
            'http.lowSpeedTime' => '30',
        ];
        if (config('runtime_sources.allow_insecure')) {
            $config['protocol.http.allow'] = 'always';
            $config['protocol.git.allow'] = 'always';
        }
        // the test suite's local repositories (file://); never set from the environment
        foreach ((array) config('runtime_sources.git_extra_protocols', []) as $protocol) {
            if (is_string($protocol) && preg_match('/^[a-z]+$/', $protocol)) {
                $config["protocol.{$protocol}.allow"] = 'always';
            }
        }

        $env = ['GIT_ASKPASS' => 'true', 'SSH_ASKPASS' => 'true', 'GIT_LFS_SKIP_SMUDGE' => '1'];

        if (($token = $source->credential('git', 'token')) !== null && ! SourceLocation::isSsh($source->url)) {
            $user = $source->credential('git', 'username') ?? 'x-access-token';
            $config['http.extraHeader'] = 'Authorization: Basic '.base64_encode("{$user}:{$token}");
        }

        $ssh = ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', '-o', 'IdentitiesOnly=yes'];
        if (($key = $source->credential('git', 'ssh_key')) !== null) {
            $keyFile = $work.DIRECTORY_SEPARATOR.'id_key';
            file_put_contents($keyFile, rtrim($key)."\n");
            chmod($keyFile, 0600);
            array_push($ssh, '-i', $keyFile);
        }
        $knownHosts = $work.DIRECTORY_SEPARATOR.'known_hosts';
        if (($hosts = $source->credential('git', 'known_hosts')) !== null) {
            file_put_contents($knownHosts, rtrim($hosts)."\n");
            array_push($ssh, '-o', 'StrictHostKeyChecking=yes');
        } else {
            array_push($ssh, '-o', 'StrictHostKeyChecking=accept-new');
        }
        array_push($ssh, '-o', 'UserKnownHostsFile='.$knownHosts);
        $env['GIT_SSH_COMMAND'] = implode(' ', array_map('escapeshellarg', $ssh));

        return [$env, $config];
    }

    /** The commit the source's ref names now (ls-remote; a tag's peeled commit). */
    private function remoteRevision(RuntimeSource $source, array $env, array $config): string
    {
        $ref = $this->branchOrTag($source->ref);
        $patterns = $ref === null ? ['HEAD'] : (str_starts_with($ref, 'refs/')
            ? [$ref, "{$ref}^{}"]
            : ["refs/heads/{$ref}", "refs/tags/{$ref}", "refs/tags/{$ref}^{}"]);

        $out = $this->run($source, ['ls-remote', '--', $source->url, ...$patterns], null, $env, $config);

        $found = [];
        foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{40,64})\s+(\S+)$/', trim($line), $m)) {
                $found[$m[2]] = $m[1];
            }
        }
        foreach ($ref === null ? ['HEAD'] : array_reverse($patterns) as $pattern) {
            // a peeled tag (^{}) first: the commit, not the tag object
            if (isset($found[$pattern])) {
                return $found[$pattern];
            }
        }

        throw RuntimeSourceException::unreachable($ref === null
            ? 'The repository has no default branch'
            : "The repository has no branch or tag {$ref}");
    }

    private function branchOrTag(?string $ref): ?string
    {
        $ref = trim((string) $ref);

        return $ref === '' ? null : $ref;
    }

    /**
     * @throws RuntimeSourceException
     */
    private function run(RuntimeSource $source, array $args, ?string $cwd, array $env, array $config): string
    {
        [$code, $out, $err] = $this->git->attempt($args, $cwd, $env, null, max(10, (int) config('runtime_sources.git_timeout', 120)), $config);
        if ($code === 0) {
            return $out;
        }

        $message = SourceCredentials::maskFor(trim($err !== '' ? $err : $out), $source);
        $message = mb_substr($message !== '' ? $message : "git {$args[0]} exited with {$code}", 0, 1000);

        $credentials = $this->sendsCredentials($source);
        $status = self::failureStatus($message, $credentials);
        if ($status === RuntimeSource::NOT_FOUND && ! $credentials) {
            $message = mb_substr('The repository does not exist or is not public (a private one needs a token or a deploy key): '.$message, 0, 1000);
        }

        throw new RuntimeSourceException($status, $message);
    }

    /**
     * The status of a failed git command:
     *  - refused (401 / 403, a credential prompt, a denied key) with the source's credentials:
     *    auth_failed;
     *  - refused without credentials, or the host says the repository does not exist: not_found —
     *    to a reader without credentials a missing repository and a private one look the same
     *    (GitHub asks for a username for both);
     *  - a host key that does not match the source's known_hosts: auth_failed (without known_hosts
     *    the key is accepted on first use, so only stored known_hosts can refuse it);
     *  - anything else: unreachable.
     */
    public static function failureStatus(string $message, bool $withCredentials): string
    {
        $refused = (bool) preg_match('/Authentication failed|could not read Username|could not read Password|Permission denied|terminal prompts disabled|HTTP Basic: Access denied|returned error: 40[13]|Invalid username or password/i', $message);
        $missing = (bool) preg_match('/Repository not found|repository \S+ not found|returned error: 404|The project you were looking for could not be found/i', $message);

        return match (true) {
            (bool) preg_match('/Host key verification failed/i', $message) => RuntimeSource::AUTH_FAILED,
            $refused && $withCredentials => RuntimeSource::AUTH_FAILED,
            $refused, $missing => RuntimeSource::NOT_FOUND,
            default => RuntimeSource::UNREACHABLE,
        };
    }

    /** Whether the git commands carry the source's credentials (a token over HTTPS, a deploy key over SSH). */
    private function sendsCredentials(RuntimeSource $source): bool
    {
        return SourceLocation::isSsh($source->url)
            ? $source->credential('git', 'ssh_key') !== null
            : $source->credential('git', 'token') !== null;
    }

    /**
     * The runtime entries of a checkout.
     *
     * @return list<array{path: string, data: array|null, files: array<string, string>, problem: string|null}>
     */
    public function entries(string $checkout, string $path): array
    {
        $root = realpath($checkout);
        $dir = realpath($checkout.DIRECTORY_SEPARATOR.$path);
        if ($root === false || $dir === false || ! is_dir($dir) || ! self::inside($dir, $root)) {
            throw RuntimeSourceException::invalid("The repository has no directory {$path}");
        }

        $manifests = glob($dir.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'runtime.yaml') ?: [];
        sort($manifests);
        $max = (int) config('runtime_sources.max_runtimes', 200);
        if (count($manifests) > $max) {
            throw RuntimeSourceException::invalid("The repository has more than {$max} runtimes under {$path}");
        }

        $entries = [];
        foreach ($manifests as $file) {
            $runtimeDir = dirname($file);
            $relative = $path.'/'.basename($runtimeDir).'/runtime.yaml';
            try {
                $entries[] = ['path' => $relative] + $this->entry($file, $runtimeDir, $root);
            } catch (InvalidManifestException $e) {
                $first = $e->problems[0] ?? ['path' => '/', 'message' => 'is not valid'];
                $entries[] = ['path' => $relative, 'data' => null, 'files' => [], 'problem' => "{$first['path']}: {$first['message']}"];
            }
        }

        return $entries;
    }

    /**
     * @return array{data: array|null, files: array<string, string>, problem: string|null}
     *
     * @throws InvalidManifestException
     */
    private function entry(string $file, string $runtimeDir, string $root): array
    {
        if (is_link($file) || ! self::inside((string) realpath($file), $root)) {
            return ['data' => null, 'files' => [], 'problem' => 'is a link'];
        }
        if (filesize($file) > self::MAX_MANIFEST_BYTES) {
            return ['data' => null, 'files' => [], 'problem' => 'is larger than '.self::MAX_MANIFEST_BYTES.' bytes'];
        }

        $data = $this->validator->decode(str_replace("\r\n", "\n", (string) file_get_contents($file)));

        // "@file" build files (the runtime's and its versions'): next to the manifest, inside the
        // runtime's directory
        $inliner = fn (string $path) => function ($value) use ($runtimeDir, $path) {
            if (! is_string($value) || ! str_starts_with($value, '@')) {
                return $value;
            }
            $target = $runtimeDir.DIRECTORY_SEPARATOR.substr($value, 1);
            $real = realpath($target);
            if ($real === false || is_link($target) || ! is_file($real) || ! self::inside($real, $runtimeDir)) {
                throw new InvalidManifestException([['path' => $path, 'message' => "references {$value}, which is not a file next to the manifest"]]);
            }

            return str_replace("\r\n", "\n", (string) file_get_contents($real));
        };
        foreach ((array) ($data['build']['files'] ?? []) as $name => $content) {
            $inline = $inliner("/build/files/{$name}");
            $data['build']['files'][$name] = is_array($content) ? array_map($inline, $content) : $inline($content);
        }
        foreach ((array) ($data['versions'] ?? []) as $i => $version) {
            foreach ((array) (is_array($version) ? ($version['build']['files'] ?? []) : []) as $name => $content) {
                $inline = $inliner("/versions/{$i}/build/files/{$name}");
                $data['versions'][$i]['build']['files'][$name] = is_array($content) ? array_map($inline, $content) : $inline($content);
            }
        }

        $files = [];
        $usesDockerfile = collect((array) ($data['versions'] ?? []))->contains(fn ($v) => is_array($v) && isset($v['dockerfile']));
        if ($usesDockerfile) {
            $total = 0;
            foreach (scandir($runtimeDir) ?: [] as $name) {
                $full = $runtimeDir.DIRECTORY_SEPARATOR.$name;
                if ($name === 'runtime.yaml' || $name === '.' || $name === '..' || is_link($full) || ! is_file($full)) {
                    continue;
                }
                if (! preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name)) {
                    return ['data' => null, 'files' => [], 'problem' => "{$name}: file names next to a dockerfile runtime may only use letters, digits, '.', '-' and '_'"];
                }
                $total += (int) filesize($full);
                $files[$name] = (string) file_get_contents($full);
            }
            if (count($files) > self::MAX_FILES || $total > self::MAX_FILES_BYTES) {
                return ['data' => null, 'files' => [], 'problem' => 'the files next to the manifest exceed '.self::MAX_FILES.' files or '.intdiv(self::MAX_FILES_BYTES, 1024).' KB (use a git version for larger build contexts)'];
            }
        }

        return ['data' => $data, 'files' => $files, 'problem' => null];
    }

    private static function inside(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return $path !== '' && (str_starts_with(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, $root));
    }

    private static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            @chmod($item->getPathname(), 0700);
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
