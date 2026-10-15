<?php

namespace App\Http\Controllers\Api\Sandboxes;

use App\Models\SandboxProcess;
use App\Services\Sandboxes\SandboxProcesses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @tags Sandboxes
 *
 * Background processes of a sandbox: start, list, state, logs (tail or SSE follow), signal.
 */
class SandboxProcessesController extends SandboxApiController
{
    public function __construct(private SandboxProcesses $processes) {}

    public function store(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        $data = $request->validate([
            'cmd' => 'required',
            'env' => 'nullable|array|max:100',
            'cwd' => 'nullable|string|max:4096',
        ]);
        $cmd = $data['cmd'];
        if (! (is_string($cmd) && trim($cmd) !== '') && ! (is_array($cmd) && array_is_list($cmd) && $cmd !== [])) {
            return response()->json(['message' => __('sandboxes.exec_cmd_required'), 'errors' => ['cmd' => [__('sandboxes.exec_cmd_required')]]], 422);
        }

        $process = $this->processes->start($row, $cmd, $this->env($request), $data['cwd'] ?? null, $this->userId($request));

        return response()->json(['data' => self::shape($process)], 201);
    }

    public function index(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');

        return response()->json(['data' => $this->processes->list($row)->map(fn ($p) => self::shape($p))->values()]);
    }

    public function show(Request $request, string $sandbox, string $process): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');

        return response()->json(['data' => self::shape($this->processes->get($row, $process))]);
    }

    /** Tail of stdout / stderr; follow=1 streams them (SSE) until the process ends. */
    public function logs(Request $request, string $sandbox, string $process): Response
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        $proc = $this->processes->get($row, $process);

        if ($request->boolean('follow')) {
            return SseStream::response(function (callable $emit, callable $tick) use ($row, $proc) {
                $emit('start', ['process_id' => $proc->id, 'sandbox_id' => $row->id]);
                $result = $this->processes->follow($row, $proc, fn (string $type, string $chunk) => $emit($type, SseStream::chunk($chunk)), $tick);
                $this->processes->refresh($row, [$proc]);
                $proc->refresh();
                $emit('exit', ['exit_code' => $proc->exit_code ?? $result->exitCode, 'status' => $proc->status]);
            });
        }

        return response()->json(['data' => $this->processes->logs($row, $proc, (int) $request->query('tail_bytes', 65536))]);
    }

    public function destroy(Request $request, string $sandbox, string $process): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');
        $proc = $this->processes->get($row, $process);

        return response()->json(['data' => self::shape($this->processes->kill($row, $proc, (string) $request->query('signal', $request->input('signal', 'TERM')), $this->userId($request)))]);
    }

    public static function shape(SandboxProcess $p): array
    {
        return [
            'id' => $p->id,
            'sandbox_id' => $p->sandbox_id,
            'pid' => $p->pid,
            'command' => $p->command,
            'cwd' => $p->cwd,
            'status' => $p->status,
            'exit_code' => $p->exit_code,
            'started_at' => $p->started_at?->toIso8601String(),
            'ended_at' => $p->ended_at?->toIso8601String(),
        ];
    }
}
