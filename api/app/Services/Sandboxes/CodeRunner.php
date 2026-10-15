<?php

namespace App\Services\Sandboxes;

use App\Models\Sandbox;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Sandboxes\Exec\ExecResult;
use App\Services\Sandboxes\Exec\ExecScript;
use App\Services\Sandboxes\Tar\TarReader;
use App\Support\RequestTime;
use Illuminate\Support\Str;

/**
 * Code interpreter helper (POST /sandboxes/{id}/run): the code is written to a file in the sandbox
 * and run with the language's interpreter — config('sandbox.run.languages'), or the exec-mode
 * command of a runtime of the catalog — and the result comes back structured: stdout, stderr,
 * exit code, the files the run created or changed under /workspace and /output, and image files
 * written to /output as rich outputs (base64).
 */
class CodeRunner
{
    public function __construct(
        protected SandboxService $service,
        protected RuntimeRegistry $runtimes,
    ) {}

    /**
     * Interpreter candidates of a language: [language, extension, list<argv>].
     *
     * @throws SandboxException unknown language
     */
    public function interpreter(?string $language, Sandbox $sandbox): array
    {
        $language = strtolower(trim((string) $language));
        if ($language === '') {
            $language = (string) (config('sandbox.run.runtime_languages')[$sandbox->runtime ?? ''] ?? $sandbox->runtime ?? 'python');
        }
        $language = (string) (config('sandbox.run.aliases')[$language] ?? $language);

        $languages = (array) config('sandbox.run.languages', []);
        if (isset($languages[$language])) {
            return [$language, $languages[$language]['extension'], $languages[$language]['commands']];
        }

        // A runtime plugin that runs a file through an exec-mode command: its run.command with {file}
        $manifest = $this->runtimes->manifest($language);
        if ($manifest && $manifest->runMode() === 'exec' && ! $manifest->isCompiled()) {
            $command = $manifest->runCommand() ?? [];
            if (array_filter($command, fn ($part) => str_contains($part, '{file}')) !== []) {
                $argv = array_map(fn ($part) => str_replace(['/app/code/{file}', '{file}'], '{file}', $part), $command);

                return [$language, $manifest->extension(), [$argv]];
            }
        }

        $known = implode(', ', array_keys($languages));
        $message = __('sandboxes.language_unknown', ['language' => $language, 'known' => $known]);
        throw new SandboxException($message, 422, 'invalid', ['errors' => ['language' => [$message]]]);
    }

    /**
     * Run $code; $onOutput streams stdout / stderr as they come (the file listing after the
     * marker is held back from the stream).
     */
    public function run(Sandbox $sandbox, string $code, ?string $language = null, array $env = [], ?string $cwd = null, ?int $timeout = null, ?callable $onOutput = null, ?callable $onTick = null): array
    {
        $this->service->assertRunning($sandbox);
        [$language, $extension, $candidates] = $this->interpreter($language, $sandbox);

        $timeout = $this->service->timeout($timeout);
        RequestTime::allow($timeout + 5);
        $cwd = SandboxFiles::normalizePath($cwd ?? SandboxManifests::WORKSPACE, 'cwd');
        $runId = strtolower((string) Str::ulid());
        $file = "/tmp/.runr/code/run-{$runId}.{$extension}";
        $marker = '--runr-files-'.Str::random(20).'--';
        $maxFiles = (int) config('sandbox.run.max_files_changed', 1000);

        $streamOut = $onOutput ? $this->markerFilter($marker, $onOutput) : null;

        $sandbox->touchActivity();
        $result = $this->service->rawExec(
            $sandbox,
            $this->service->regionOf($sandbox),
            ExecScript::runCode($file, $candidates, $env, $cwd, $timeout, $sandbox->pids_limit, $marker, $maxFiles),
            $code,
            $timeout + 5,
            $streamOut,
            $onTick,
            (int) config('sandbox.exec.max_output', 10485760),
        );
        $sandbox->touchActivity();
        if ($streamOut) {
            $streamOut('flush', '');
        }

        [$stdout, $files] = $this->splitListing($result->stdout, $marker);
        $timedOut = $result->timedOut || (in_array($result->exitCode, [124, 137], true) && $result->durationMs >= $timeout * 1000);

        $outputs = $this->outputs($sandbox, $files);

        SandboxLog::record($sandbox, SandboxLog::RUN, null, [
            'language' => $language,
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMs,
            'timed_out' => $timedOut,
            'files_changed' => count($files),
            'code_bytes' => strlen($code),
        ]);

        return array_merge(
            ['language' => $language, 'exit_code' => $result->exitCode],
            ExecResult::textOrBase64('stdout', $stdout),
            ExecResult::textOrBase64('stderr', $result->stderr),
            [
                'timed_out' => $timedOut,
                'duration_ms' => $result->durationMs,
                'truncated' => $result->truncated,
                'files_changed' => $files,
                'outputs' => $outputs,
            ],
        );
    }

    /** [stdout before the marker, list<{path, size}>] */
    public function splitListing(string $stdout, string $marker): array
    {
        $pos = strrpos($stdout, "\n{$marker}\n");
        if ($pos === false) {
            return [$stdout, []];
        }

        $before = substr($stdout, 0, $pos);
        $files = [];
        foreach (explode("\n", substr($stdout, $pos + strlen($marker) + 2)) as $line) {
            if ($line === '' || ! str_contains($line, '|')) {
                continue;
            }
            [$size, $path] = explode('|', $line, 2);
            $files[] = ['path' => $path, 'size' => (int) $size];
        }

        return [$before, $files];
    }

    /**
     * Image files of /output among $files, base64, up to run.max_outputs_bytes together.
     *
     * @return list<array{path: string, mime: string, size: int, data_base64: string}>
     */
    public function outputs(Sandbox $sandbox, array $files): array
    {
        $types = (array) config('sandbox.run.image_extensions', []);
        $budget = (int) config('sandbox.run.max_outputs_bytes', 10485760);
        $prefix = rtrim((string) config('sandbox.run.output_dir', SandboxManifests::OUTPUT), '/').'/';

        $wanted = [];
        $total = 0;
        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
            if (! str_starts_with($file['path'], $prefix) || ! isset($types[$ext])) {
                continue;
            }
            if ($total + $file['size'] > $budget) {
                continue;
            }
            $total += $file['size'];
            $wanted[$file['path']] = $types[$ext];
        }

        if ($wanted === []) {
            return [];
        }

        $result = $this->service->rawExec($sandbox, $this->service->regionOf($sandbox), ExecScript::tarFiles(array_keys($wanted)), null, 120, null, null, $budget + 1024 * 1024);
        if ($result->stdout === '') {
            return [];
        }

        try {
            $entries = TarReader::entries($result->stdout);
        } catch (\InvalidArgumentException) {
            return [];
        }

        $outputs = [];
        foreach ($entries as $entry) {
            $path = '/'.ltrim($entry['name'], './');
            if ($entry['type'] !== 'file' || ! isset($wanted[$path])) {
                continue;
            }
            $outputs[] = [
                'path' => $path,
                'mime' => $wanted[$path],
                'size' => $entry['size'],
                'data_base64' => base64_encode($entry['content']),
            ];
        }

        return $outputs;
    }

    /** Streams stdout up to the marker line; stderr as it is. Call with ('flush', '') at the end. */
    private function markerFilter(string $marker, callable $onOutput): callable
    {
        $needle = "\n{$marker}\n";
        $pending = '';
        $done = false;

        return function (string $type, string $chunk) use (&$pending, &$done, $needle, $onOutput) {
            if ($type === 'stderr') {
                $onOutput('stderr', $chunk);

                return;
            }
            if ($type === 'flush') {
                if (! $done && $pending !== '') {
                    $onOutput('stdout', $pending);
                }
                $pending = '';

                return;
            }
            if ($done) {
                return;
            }

            $pending .= $chunk;
            $pos = strpos($pending, $needle);
            if ($pos !== false) {
                if ($pos > 0) {
                    $onOutput('stdout', substr($pending, 0, $pos));
                }
                $pending = '';
                $done = true;

                return;
            }

            // Keep a tail that could be the start of the marker line
            $keep = strlen($needle) - 1;
            if (strlen($pending) > $keep) {
                $onOutput('stdout', substr($pending, 0, -$keep));
                $pending = substr($pending, -$keep);
            }
        };
    }
}
