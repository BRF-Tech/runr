<?php

namespace App\Http\Controllers\Api\Sandboxes;

use App\Models\Sandbox;
use App\Services\Audit\AuditLogger;
use App\Services\Sandboxes\CodeRunner;
use App\Services\Sandboxes\Exec\ExecScript;
use App\Services\Sandboxes\SandboxFiles;
use App\Services\Sandboxes\SandboxLog;
use App\Services\Sandboxes\SandboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * @tags Sandboxes
 *
 * Commands in a sandbox: exec (JSON result, Server-Sent Events, or a WebSocket token), the code
 * interpreter (run), and the interactive terminal (the console's web-shell WebSocket).
 */
class SandboxExecController extends SandboxApiController
{
    /** Largest terminal width / height of a TTY exec (columns, rows) */
    public const MAX_TERMINAL_SIZE = 1000;

    public function __construct(
        private SandboxService $sandboxes,
        private CodeRunner $runner,
    ) {}

    /**
     * Run a command. JSON result, or SSE (Accept: text/event-stream / stream: true).
     */
    public function exec(Request $request, string $sandbox): Response
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        $data = $this->validateExec($request);
        $this->sandboxes->assertRunning($row);

        // code + language: the interpreter without the structured extras
        if (! isset($data['cmd']) && isset($data['code'])) {
            return $this->run($request, $sandbox);
        }

        $cmd = $data['cmd'];
        $env = $this->env($request);
        $cwd = isset($data['cwd']) ? SandboxFiles::normalizePath($data['cwd'], 'cwd') : null;
        $stdin = $this->stdin($request);
        $execId = strtolower((string) Str::ulid());

        if (SseStream::wanted($request)) {
            return SseStream::response(function (callable $emit, callable $tick) use ($row, $cmd, $env, $cwd, $stdin, $data, $execId) {
                $emit('start', ['exec_id' => $execId, 'sandbox_id' => $row->id]);
                $result = $this->sandboxes->exec($row, $cmd, $env, $cwd, $stdin, $data['timeout'] ?? null,
                    fn (string $type, string $chunk) => $emit($type, SseStream::chunk($chunk)), $tick, $execId);

                if (connection_aborted()) {
                    $this->killExec($row, $execId);
                }
                $this->log($row, $cmd, $result->exitCode, $result->durationMs, $result->timedOut, 'sse');
                $emit('exit', [
                    'exit_code' => $result->exitCode,
                    'timed_out' => $result->timedOut,
                    'duration_ms' => $result->durationMs,
                    'truncated' => $result->truncated,
                ]);
            });
        }

        $result = $this->sandboxes->exec($row, $cmd, $env, $cwd, $stdin, $data['timeout'] ?? null, null, null, $execId);
        $this->log($row, $cmd, $result->exitCode, $result->durationMs, $result->timedOut, 'json');

        return response()->json(['data' => $result->toArray()]);
    }

    /**
     * One-time token for the exec WebSocket (/ws/sandbox-exec?token=…): output frames as they
     * come, stdin from the client, kill. With `tty` the command runs on a terminal of `cols` ×
     * `rows` (default 80 × 24) that resize frames change.
     */
    public function execWs(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        // The body of exec (without stream) and the terminal: inline, so the API document has it
        $data = $request->validate([
            'cmd' => 'required_without:code',
            'code' => 'nullable|string',
            'language' => 'nullable|string|max:32',
            'stdin' => 'nullable|string|max:'.(int) config('sandbox.exec.max_stdin', 10485760),
            'stdin_base64' => 'nullable|string',
            'timeout' => 'nullable|integer|min:1',
            'env' => 'nullable|array|max:100',
            'cwd' => 'nullable|string|max:4096',
            'tty' => 'nullable|boolean',
            'cols' => 'nullable|integer|min:1|max:'.self::MAX_TERMINAL_SIZE,
            'rows' => 'nullable|integer|min:1|max:'.self::MAX_TERMINAL_SIZE,
        ]);
        $this->assertCommand($data);
        $this->sandboxes->assertRunning($row);

        $region = $this->sandboxes->regionOf($row);
        $timeout = $this->sandboxes->timeout($data['timeout'] ?? null);
        $execId = strtolower((string) Str::ulid());
        $cwd = isset($data['cwd']) ? SandboxFiles::normalizePath($data['cwd'], 'cwd') : null;
        $tty = (bool) ($data['tty'] ?? false);

        if (! isset($data['cmd']) && isset($data['code'])) {
            [, , $candidates] = $this->runner->interpreter($data['language'] ?? null, $row);
            $data['cmd'] = $this->interpreterCommand($candidates, (string) $data['code']);
        }

        $argv = ExecScript::command($data['cmd'], $this->env($request), $cwd, $timeout, $row->pids_limit, $execId, $tty);
        $token = Str::uuid()->toString();
        $ttl = (int) config('sandbox.exec.ws_token_ttl', 60);

        // Encrypted like the web shell's session config: it carries the region kubeconfig
        Redis::setex("sandbox:exec:{$token}", $ttl, Crypt::encryptString(json_encode([
            'kubeconfig' => $region->kubeconfig,
            'namespace' => $row->namespace,
            'pod' => $row->pod_name,
            'container' => 'sandbox',
            'argv' => $argv,
            'stdin' => ($stdin = $this->stdin($request)) !== null ? base64_encode($stdin) : null,
            'timeout' => $timeout,
            'max_output' => (int) config('sandbox.exec.max_output', 10485760),
            'sandbox_id' => $row->id,
            'exec_id' => $execId,
            'user_id' => $this->userId($request),
            'command' => SandboxLog::commandLine($data['cmd']),
            'tty' => $tty,
            'cols' => (int) ($data['cols'] ?? 80),
            'rows' => (int) ($data['rows'] ?? 24),
        ])));

        $row->touchActivity();

        return response()->json(['data' => [
            'token' => $token,
            'url' => "/ws/sandbox-exec?token={$token}",
            'exec_id' => $execId,
            'expires_in' => $ttl,
            'tty' => $tty,
        ]]);
    }

    /**
     * Code interpreter: run code with the language's interpreter; structured result (stdout,
     * stderr, exit code, files changed, image outputs of /output).
     */
    public function run(Request $request, string $sandbox): Response
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        $data = $request->validate([
            'code' => 'required|string|max:'.(int) config('sandbox.exec.max_stdin', 10485760),
            'language' => 'nullable|string|max:32',
            'timeout' => 'nullable|integer|min:1',
            'env' => 'nullable|array|max:100',
            'cwd' => 'nullable|string|max:4096',
            'stream' => 'nullable|boolean',
        ]);
        $this->sandboxes->assertRunning($row);
        $env = $this->env($request);

        if (SseStream::wanted($request)) {
            return SseStream::response(function (callable $emit, callable $tick) use ($row, $data, $env) {
                $emit('start', ['sandbox_id' => $row->id]);
                $result = $this->runner->run($row, $data['code'], $data['language'] ?? null, $env, $data['cwd'] ?? null, $data['timeout'] ?? null,
                    fn (string $type, string $chunk) => $emit($type, SseStream::chunk($chunk)), $tick);

                $emit('exit', [
                    'exit_code' => $result['exit_code'],
                    'timed_out' => $result['timed_out'],
                    'duration_ms' => $result['duration_ms'],
                    'truncated' => $result['truncated'],
                ]);
                $emit('result', array_diff_key($result, array_flip(['stdout', 'stderr', 'stdout_base64', 'stderr_base64'])));
            });
        }

        return response()->json(['data' => $this->runner->run($row, $data['code'], $data['language'] ?? null, $env, $data['cwd'] ?? null, $data['timeout'] ?? null)]);
    }

    /**
     * Interactive terminal: a one-time session for the web-shell WebSocket (/ws/shell?token=…).
     */
    public function terminal(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        $this->sandboxes->assertRunning($row);
        $region = $this->sandboxes->regionOf($row);

        $sessionId = Str::uuid()->toString();
        // The web shell's session format (ExecController): encrypted, with the audit context the
        // Reverb shell records shell.session.open / close under
        Redis::setex("exec:config:{$sessionId}", 120, Crypt::encryptString(json_encode([
            'audit' => app(AuditLogger::class)->currentContext($request) + [
                'target' => ['type' => 'sandbox', 'id' => $row->id, 'name' => $row->pod_name],
                'session_id' => $sessionId,
            ],
            'kubeconfig' => $region->kubeconfig,
            'namespace' => $row->namespace,
            'pod' => $row->pod_name,
            'container' => 'sandbox',
            'sandbox_id' => $row->id,
            'cols' => max(1, (int) $request->input('cols', 80)),
            'rows' => max(1, (int) $request->input('rows', 24)),
        ])));

        $row->touchActivity();
        SandboxLog::record($row, SandboxLog::TERMINAL, null, ['session' => substr($sessionId, 0, 8)], $this->userId($request));

        return response()->json(['data' => [
            'session_id' => $sessionId,
            'url' => "/ws/shell?token={$sessionId}",
        ], 'session_id' => $sessionId]);
    }

    private function validateExec(Request $request): array
    {
        $data = $request->validate([
            'cmd' => 'required_without:code',
            'code' => 'nullable|string',
            'language' => 'nullable|string|max:32',
            'stdin' => 'nullable|string|max:'.(int) config('sandbox.exec.max_stdin', 10485760),
            'stdin_base64' => 'nullable|string',
            'timeout' => 'nullable|integer|min:1',
            'env' => 'nullable|array|max:100',
            'cwd' => 'nullable|string|max:4096',
            'stream' => 'nullable|boolean',
        ]);
        $this->assertCommand($data);

        return $data;
    }

    /** A cmd is a non-empty string or a non-empty list of scalars. */
    private function assertCommand(array $data): void
    {
        if (isset($data['cmd'])) {
            $cmd = $data['cmd'];
            $valid = is_string($cmd) ? trim($cmd) !== '' : (is_array($cmd) && array_is_list($cmd) && $cmd !== [] && count(array_filter($cmd, 'is_scalar')) === count($cmd));
            if (! $valid) {
                abort(response()->json(['message' => __('sandboxes.exec_cmd_required'), 'errors' => ['cmd' => [__('sandboxes.exec_cmd_required')]]], 422));
            }
        }
    }

    /**
     * Interpreter argv for code passed on the WebSocket: sh writes the code ($1) to a file ($2)
     * and execs the first interpreter the image has. The code travels as one argument (at most
     * 120 KB — larger code goes through POST /run).
     */
    private function interpreterCommand(array $candidates, string $code): array
    {
        if (strlen($code) > 120_000) {
            abort(response()->json(['message' => __('sandboxes.ws_code_too_large'), 'errors' => ['code' => [__('sandboxes.ws_code_too_large')]]], 422));
        }

        $file = '/tmp/.runr/code/ws-'.strtolower((string) Str::ulid());
        $script = 'mkdir -p /tmp/.runr/code && printf %s "$1" > "$2" || exit 1'."\n";
        foreach ($candidates as $argv) {
            $words = implode(' ', array_map(fn ($a) => (string) $a === '{file}' ? '"$2"' : ExecScript::quote((string) $a), $argv));
            $script .= 'if command -v '.ExecScript::quote((string) $argv[0]).' >/dev/null 2>&1; then exec '.$words.'; fi'."\n";
        }
        $script .= 'echo "runr: no interpreter found in the image" >&2; exit 127';

        return ['/bin/sh', '-c', $script, 'runr', $code, $file];
    }

    private function killExec(Sandbox $row, string $execId): void
    {
        try {
            $this->sandboxes->rawExec($row, $this->sandboxes->regionOf($row), ExecScript::killExec($execId), null, 15);
        } catch (\Throwable) {
            // best effort: the in-sandbox timeout ends it anyway
        }
    }

    private function log(Sandbox $row, string|array $cmd, int $exit, int $ms, bool $timedOut, string $via): void
    {
        SandboxLog::record($row, SandboxLog::EXEC, SandboxLog::commandLine($cmd), [
            'exit_code' => $exit,
            'duration_ms' => $ms,
            'timed_out' => $timedOut,
            'via' => $via,
        ]);
    }
}
