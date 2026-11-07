<?php

namespace App\Services\Sandboxes\Exec;

/**
 * Runs a command in a sandbox container and streams its output. Bound to SelectingExecTransport:
 * the native WebSocket client (NativeExecTransport), kubectl exec (KubectlExecTransport) where
 * that cannot run it or sandbox.exec.client says so; tests bind a fake.
 *
 * $argv is the full command (ExecScript builds the wrapper that sources the sandbox env, changes to
 * the working directory and applies the timeout / PID limit). $onOutput receives each chunk as it
 * arrives: ('stdout' | 'stderr', bytes). $onTick runs about every 50 ms while the command runs;
 * returning false stops it (client gone).
 *
 * $ackId: $argv reads its input in acknowledged frames (ExecScript::ackedInput with this id): the
 * transport sends $stdin in frames of ExecScript::ACKED_FRAME_BYTES, each once the last was
 * acknowledged (ExecScript::ackMarker on stderr), then "0\n", and closes stdin at
 * ExecScript::ackEndMarker; both markers are taken out of stderr whole (MarkerFilter).
 */
interface ExecTransport
{
    /**
     * @param  list<string>  $argv
     * @param  callable(string, string): void|null  $onOutput
     * @param  callable(): (bool|null)|null  $onTick
     *
     * @throws ExecTransportException the command could not be started in the pod
     */
    public function exec(
        PodTarget $target,
        array $argv,
        ?string $stdin = null,
        int $timeoutSeconds = 60,
        ?callable $onOutput = null,
        int $maxOutputBytes = 10485760,
        ?callable $onTick = null,
        ?string $ackId = null,
    ): ExecResult;
}
