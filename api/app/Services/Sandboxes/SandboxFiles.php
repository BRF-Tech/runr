<?php

namespace App\Services\Sandboxes;

use App\Models\Sandbox;
use App\Services\Sandboxes\Exec\ExecResult;
use App\Services\Sandboxes\Exec\ExecScript;

/**
 * File transfer of the Sandbox API, through exec in the sandbox (cat / tar / find + stat), so it
 * works the same under runc, gVisor and Kata and needs nothing in the image but /bin/sh, coreutils
 * or busybox and tar. Sizes are limited by sandbox.files.max_upload_bytes / max_download_bytes.
 */
class SandboxFiles
{
    private const TIMEOUT = 300;

    public function __construct(protected SandboxService $service) {}

    /**
     * Absolute, normalized path ("." and ".." resolved, no NUL).
     *
     * @throws SandboxException
     */
    public static function normalizePath(string $path, string $field = 'path'): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || strlen($path) > 4096) {
            throw new SandboxException(__('sandboxes.path_invalid', ['path' => mb_substr($path, 0, 200)]), 422, 'invalid', ['errors' => [$field => [__('sandboxes.path_invalid', ['path' => mb_substr($path, 0, 200)])]]]);
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $part;
        }

        return '/'.implode('/', $parts);
    }

    /** @return array{path: string, is_dir: bool, entries: list<array>, truncated: bool} */
    public function list(Sandbox $sandbox, string $path): array
    {
        $path = self::normalizePath($path);
        $max = (int) config('sandbox.files.max_list_entries', 10000);

        $result = $this->run($sandbox, ExecScript::listDir($path, $max), null, 60, 8 * 1024 * 1024);
        $this->assertOk($result, $path);

        $lines = explode("\n", rtrim($result->stdout, "\n"));
        $kind = array_shift($lines);
        $entries = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 5);
            if (count($parts) < 5) {
                continue;
            }
            [$type, $size, $mtime, $mode, $full] = $parts;
            $entries[] = [
                'name' => basename($full),
                'path' => $full,
                'type' => self::type($type),
                'size' => (int) $size,
                'mode' => $mode,
                'modified_at' => ctype_digit($mtime) ? gmdate('Y-m-d\TH:i:s\Z', (int) $mtime) : null,
            ];
        }

        $truncated = count($entries) > $max;
        if ($truncated) {
            $entries = array_slice($entries, 0, $max);
        }
        usort($entries, fn ($a, $b) => [$a['type'] !== 'dir', $a['name']] <=> [$b['type'] !== 'dir', $b['name']]);

        return ['path' => $path, 'is_dir' => $kind === 'dir', 'entries' => $entries, 'truncated' => $truncated];
    }

    /** Contents of a regular file. */
    public function read(Sandbox $sandbox, string $path): string
    {
        $path = self::normalizePath($path);
        $max = (int) config('sandbox.files.max_download_bytes', 52428800);

        $result = $this->run($sandbox, ExecScript::readFile($path, $max), null, self::TIMEOUT, $max + 1);
        if ($result->exitCode === 3) {
            throw new SandboxException(__('sandboxes.file_too_large', ['max' => $max]), 413, 'file_too_large');
        }
        $this->assertOk($result, $path);

        SandboxLog::record($sandbox, SandboxLog::FILE_DOWNLOAD, $path, ['op' => 'read', 'bytes' => strlen($result->stdout)]);

        return $result->stdout;
    }

    /** Write a file (parents created). */
    public function write(Sandbox $sandbox, string $path, string $content, ?string $mode = null): array
    {
        $path = self::normalizePath($path);
        $this->assertUploadSize(strlen($content));
        if ($mode !== null && ! preg_match('/^0?[0-7]{3}$/', $mode)) {
            throw new SandboxException(__('sandboxes.mode_invalid'), 422, 'invalid', ['errors' => ['mode' => [__('sandboxes.mode_invalid')]]]);
        }

        $result = $this->run($sandbox, ExecScript::writeFile($path, $mode), $content, self::TIMEOUT);
        $this->assertOk($result, $path);

        SandboxLog::record($sandbox, SandboxLog::FILE_UPLOAD, $path, ['op' => 'write', 'bytes' => strlen($content)]);

        return ['path' => $path, 'size' => strlen($content)];
    }

    public function remove(Sandbox $sandbox, string $path, bool $recursive): array
    {
        $path = self::normalizePath($path);
        if ($path === '/') {
            throw new SandboxException(__('sandboxes.path_root'), 422, 'invalid', ['errors' => ['path' => [__('sandboxes.path_root')]]]);
        }

        $result = $this->run($sandbox, ExecScript::remove($path, $recursive), null, 120);
        $this->assertOk($result, $path);

        SandboxLog::record($sandbox, SandboxLog::FILE_DELETE, $path, ['recursive' => $recursive]);

        return ['path' => $path, 'deleted' => true];
    }

    public function mkdir(Sandbox $sandbox, string $path): array
    {
        $path = self::normalizePath($path);
        $result = $this->run($sandbox, ExecScript::mkdir($path), null, 60);
        $this->assertOk($result, $path);

        SandboxLog::record($sandbox, SandboxLog::FILE_UPLOAD, $path, ['op' => 'mkdir']);

        return ['path' => $path];
    }

    /** Tar of a directory's contents. */
    public function archive(Sandbox $sandbox, string $path): string
    {
        $path = self::normalizePath($path);
        $max = (int) config('sandbox.files.max_download_bytes', 52428800);

        $result = $this->run($sandbox, ExecScript::createTar($path), null, self::TIMEOUT, $max + 1);
        if (strlen($result->stdout) > $max || $result->truncated) {
            throw new SandboxException(__('sandboxes.file_too_large', ['max' => $max]), 413, 'file_too_large');
        }
        $this->assertOk($result, $path);

        SandboxLog::record($sandbox, SandboxLog::FILE_DOWNLOAD, $path, ['op' => 'archive', 'bytes' => strlen($result->stdout)]);

        return $result->stdout;
    }

    /** Extract a tar into a directory (created when missing). */
    public function extract(Sandbox $sandbox, string $path, string $tar): array
    {
        $path = self::normalizePath($path);
        $this->assertUploadSize(strlen($tar));

        $result = $this->run($sandbox, ExecScript::extractTarAt($path), $tar, self::TIMEOUT);
        $this->assertOk($result, $path);

        SandboxLog::record($sandbox, SandboxLog::FILE_UPLOAD, $path, ['op' => 'extract', 'bytes' => strlen($tar)]);

        return ['path' => $path, 'bytes' => strlen($tar)];
    }

    public function assertUploadSize(int $bytes): void
    {
        $max = (int) config('sandbox.files.max_upload_bytes', 52428800);
        if ($bytes > $max) {
            throw new SandboxException(__('sandboxes.file_too_large', ['max' => $max]), 413, 'file_too_large');
        }
    }

    /** Internal script in a running sandbox, resetting its idle timer. */
    protected function run(Sandbox $sandbox, array $argv, ?string $stdin, int $timeout, ?int $maxOutput = null): ExecResult
    {
        $this->service->assertRunning($sandbox);
        $sandbox->touchActivity();

        return $this->service->rawExec($sandbox, $this->service->regionOf($sandbox), $argv, $stdin, $timeout, null, null, $maxOutput);
    }

    /** exit 2 → 404 (no such file), anything else non-zero → 422 with the command's message. */
    protected function assertOk(ExecResult $result, string $path): void
    {
        if ($result->ok()) {
            return;
        }

        $message = trim(preg_replace('/^runr: /m', '', $result->stderr)) ?: __('sandboxes.file_op_failed', ['path' => $path]);
        if ($result->exitCode === 2 && str_contains($result->stderr, 'runr: ')) {
            throw new SandboxException($message, 404, 'not_found');
        }

        throw new SandboxException($message, 422, 'file_op_failed', ['exit_code' => $result->exitCode]);
    }

    private static function type(string $statType): string
    {
        return match (true) {
            str_contains($statType, 'directory') => 'dir',
            str_contains($statType, 'symbolic link') => 'symlink',
            str_contains($statType, 'regular') => 'file',
            default => 'other',
        };
    }
}
