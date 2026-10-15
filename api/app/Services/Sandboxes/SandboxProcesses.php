<?php

namespace App\Services\Sandboxes;

use App\Models\Sandbox;
use App\Models\SandboxProcess;
use App\Services\Sandboxes\Exec\ExecResult;
use App\Services\Sandboxes\Exec\ExecScript;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Long-running background processes (servers, watchers, training loops): started detached with
 * nohup (and setsid where the image has it) so they outlive the exec, their output in
 * /tmp/.runr/proc/<id>/{out,err} and their exit code in …/exit. The rows keep the OS pid; states
 * are read from /proc in one exec per listing.
 */
class SandboxProcesses
{
    public const SIGNALS = ['TERM', 'KILL', 'INT', 'HUP', 'QUIT', 'USR1', 'USR2', 'STOP', 'CONT'];

    public function __construct(protected SandboxService $service) {}

    /** @param string|list<string> $cmd */
    public function start(Sandbox $sandbox, string|array $cmd, array $env = [], ?string $cwd = null, ?string $userId = null): SandboxProcess
    {
        $this->service->assertRunning($sandbox);
        $cwd = SandboxFiles::normalizePath($cwd ?? SandboxManifests::WORKSPACE, 'cwd');

        $process = new SandboxProcess([
            'sandbox_id' => $sandbox->id,
            'command' => SandboxLog::commandLine($cmd),
            'cwd' => $cwd,
            'status' => SandboxProcess::STATUS_RUNNING,
            'created_by' => $userId,
            'started_at' => now(),
        ]);
        $process->id = (string) Str::ulid();

        $result = $this->run($sandbox, ExecScript::startProcess($cmd, $process->dir(), $env, $cwd, $sandbox->pids_limit), 30);
        $pid = (int) trim($result->stdout);
        if (! $result->ok() || $pid <= 0) {
            throw new SandboxException(__('sandboxes.process_start_failed', ['detail' => trim($result->stderr) ?: "exit {$result->exitCode}"]), 422, 'process_failed');
        }

        $process->pid = $pid;
        $process->save();

        SandboxLog::record($sandbox, SandboxLog::PROCESS_START, $process->command, ['process_id' => $process->id, 'pid' => $pid], $userId);

        return $process;
    }

    /**
     * Rows of the sandbox with their state read from the sandbox (while it runs).
     *
     * @return Collection<int, SandboxProcess>
     */
    public function list(Sandbox $sandbox)
    {
        $processes = SandboxProcess::where('sandbox_id', $sandbox->id)->orderByDesc('started_at')->get();
        $this->refresh($sandbox, $processes->where('status', SandboxProcess::STATUS_RUNNING)->values()->all());

        return $processes;
    }

    /** @param list<SandboxProcess> $processes */
    public function refresh(Sandbox $sandbox, array $processes): void
    {
        $running = array_values(array_filter($processes, fn (SandboxProcess $p) => $p->status === SandboxProcess::STATUS_RUNNING && $p->pid));
        if ($running === [] || $sandbox->status !== Sandbox::STATUS_RUNNING) {
            return;
        }

        $items = array_map(fn (SandboxProcess $p) => ['id' => $p->id, 'pid' => $p->pid, 'dir' => $p->dir()], $running);
        $result = $this->run($sandbox, ExecScript::processStates($items), 30, false);
        if (! $result->ok()) {
            return;
        }

        $states = [];
        foreach (explode("\n", trim($result->stdout)) as $line) {
            $parts = explode('|', $line);
            if (count($parts) === 3) {
                $states[$parts[0]] = ['state' => $parts[1], 'exit' => $parts[2]];
            }
        }

        foreach ($running as $process) {
            $state = $states[$process->id] ?? null;
            if (! $state || $state['state'] === 'running') {
                continue;
            }
            $exit = $state['exit'] !== '' && is_numeric($state['exit']) ? (int) $state['exit'] : null;
            $process->update([
                'status' => $exit === null ? SandboxProcess::STATUS_KILLED : SandboxProcess::STATUS_EXITED,
                'exit_code' => $exit,
                'ended_at' => now(),
            ]);
        }
    }

    public function get(Sandbox $sandbox, string $id): SandboxProcess
    {
        $process = SandboxProcess::where('sandbox_id', $sandbox->id)->whereKey($id)->first();
        if (! $process) {
            throw new SandboxException(__('sandboxes.process_unknown'), 404, 'not_found');
        }
        $this->refresh($sandbox, [$process]);

        return $process->refresh();
    }

    /** @return array{stdout: string, stderr: string, truncated: bool} */
    public function logs(Sandbox $sandbox, SandboxProcess $process, int $tailBytes = 65536): array
    {
        $tailBytes = max(1, min($tailBytes, 4 * 1024 * 1024));
        $marker = '--runr-'.Str::random(16).'--';
        $result = $this->run($sandbox, ExecScript::processLogs($process->dir(), $tailBytes, $marker), 30);

        $pos = strrpos($result->stdout, "\n{$marker}\n");
        $out = $pos === false ? $result->stdout : substr($result->stdout, 0, $pos);
        $err = $pos === false ? '' : substr($result->stdout, $pos + strlen($marker) + 2);

        return array_merge(
            ExecResult::textOrBase64('stdout', $out),
            ExecResult::textOrBase64('stderr', $err),
            ['truncated' => strlen($out) >= $tailBytes || strlen($err) >= $tailBytes],
        );
    }

    /** Follow a process's output as it is written (SSE), until it ends. */
    public function follow(Sandbox $sandbox, SandboxProcess $process, callable $onOutput, ?callable $onTick = null): ExecResult
    {
        $this->service->assertRunning($sandbox);

        return $this->service->rawExec($sandbox, $this->service->regionOf($sandbox), ExecScript::followProcess($process->dir(), (int) $process->pid), null,
            (int) config('sandbox.exec.max_timeout', 3600), $onOutput, $onTick, (int) config('sandbox.exec.max_output', 10485760));
    }

    public function kill(Sandbox $sandbox, SandboxProcess $process, string $signal = 'TERM', ?string $userId = null): SandboxProcess
    {
        $signal = strtoupper($signal);
        if (! in_array($signal, self::SIGNALS, true)) {
            throw new SandboxException(__('sandboxes.signal_invalid', ['signals' => implode(', ', self::SIGNALS)]), 422, 'invalid', ['errors' => ['signal' => [__('sandboxes.signal_invalid', ['signals' => implode(', ', self::SIGNALS)])]]]);
        }

        if ($process->status === SandboxProcess::STATUS_RUNNING && $process->pid) {
            $this->run($sandbox, ExecScript::killProcess((int) $process->pid, $signal), 30);
            if (in_array($signal, ['KILL', 'TERM', 'INT', 'HUP', 'QUIT'], true)) {
                usleep(200_000);
                $this->refresh($sandbox, [$process]);
            }
        }

        SandboxLog::record($sandbox, SandboxLog::PROCESS_KILL, $process->command, ['process_id' => $process->id, 'signal' => $signal], $userId);

        return $process->refresh();
    }

    protected function run(Sandbox $sandbox, array $argv, int $timeout, bool $touch = true): ExecResult
    {
        $this->service->assertRunning($sandbox);
        if ($touch) {
            $sandbox->touchActivity();
        }

        return $this->service->rawExec($sandbox, $this->service->regionOf($sandbox), $argv, null, $timeout, null, null, 8 * 1024 * 1024);
    }
}
