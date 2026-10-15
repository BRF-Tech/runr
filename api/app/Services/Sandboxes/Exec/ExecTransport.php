<?php

namespace App\Services\Sandboxes\Exec;

/**
 * Runs a command in a sandbox container and streams its output. Bound to KubectlExecTransport
 * (kubectl exec, like the web shell); tests bind a fake.
 *
 * $argv is the full command (ExecScript builds the wrapper that sources the sandbox env, changes to
 * the working directory and applies the timeout / PID limit). $onOutput receives each chunk as it
 * arrives: ('stdout' | 'stderr', bytes). $onTick runs about every 50 ms while the command runs;
 * returning false stops it (client gone).
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
    ): ExecResult;
}
