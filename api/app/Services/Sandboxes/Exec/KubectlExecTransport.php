<?php

namespace App\Services\Sandboxes\Exec;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * ExecTransport over `kubectl exec` (the binary the web shell uses), without a TTY: stdout and
 * stderr stay separate and binary-safe, stdin is piped when given, and kubectl exits with the
 * remote command's exit code (its "command terminated with exit code N" line is filtered out).
 *
 * The command's own timeout is enforced inside the sandbox (ExecScript: timeout -s KILL); the
 * local process gets 15 s more and is killed then, which also covers an unreachable API server.
 *
 * Acknowledged input (ExecTransport, ExecScript::ackedInput) goes frame by frame through an
 * InputStream: the next frame once the last one's acknowledgement came on stderr.
 */
final class KubectlExecTransport implements ExecTransport
{
    private const LOCAL_GRACE_SECONDS = 15;

    public function exec(
        PodTarget $target,
        array $argv,
        ?string $stdin = null,
        int $timeoutSeconds = 60,
        ?callable $onOutput = null,
        int $maxOutputBytes = 10485760,
        ?callable $onTick = null,
        ?string $ackId = null,
    ): ExecResult {
        $kubeconfig = KubectlCommand::writeKubeconfig($target->kubeconfig);
        $started = hrtime(true);
        $stdout = '';
        $stderr = '';
        $rawErrTail = '';
        $truncated = false;
        $timedOut = false;
        $stopped = false;
        $filter = new TrailerFilter;

        $append = function (string $type, string $chunk) use (&$stdout, &$stderr, &$truncated, $maxOutputBytes, $onOutput) {
            if ($chunk === '') {
                return;
            }
            $current = $type === 'stdout' ? strlen($stdout) : strlen($stderr);
            $room = max(0, $maxOutputBytes - $current);
            if (strlen($chunk) > $room) {
                $chunk = substr($chunk, 0, $room);
                $truncated = true;
            }
            if ($chunk === '') {
                return;
            }
            if ($type === 'stdout') {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
            if ($onOutput) {
                $onOutput($type, $chunk);
            }
        };

        // Acknowledged input: the next frame once the reader acknowledged the last
        $frames = $ackId !== null && $stdin !== null ? new InputStream : null;
        $acks = $frames !== null ? new MarkerFilter([ExecScript::ackMarker($ackId), ExecScript::ackEndMarker($ackId)]) : null;
        $offset = 0;
        $endFrames = function () use (&$frames) {
            $frames?->close();
            $frames = null;
        };
        $nextFrame = function () use (&$frames, &$offset, $stdin, $endFrames) {
            if ($frames === null) {
                return;
            }
            if ($offset < strlen((string) $stdin)) {
                $piece = substr((string) $stdin, $offset, ExecScript::ACKED_FRAME_BYTES);
                $offset += strlen($piece);
                $frames->write(strlen($piece)."\n".$piece);

                return;
            }
            $frames->write("0\n");
            $endFrames();
        };
        $nextFrame();

        $readErr = function (Process $process) use (&$rawErrTail, $filter, $append, $nextFrame, $endFrames, $acks, $ackId) {
            $chunk = $process->getIncrementalErrorOutput();
            $process->clearErrorOutput();
            if ($acks !== null && $chunk !== '') {
                [$chunk, $found] = $acks->push($chunk);
                foreach ($found as $marker) {
                    if ($marker === ExecScript::ackMarker((string) $ackId)) {
                        $nextFrame();
                    } else {
                        // The reader ended (before the input did, when its command failed): no more frames
                        $endFrames();
                    }
                }
            }
            if ($chunk !== '') {
                $rawErrTail = substr($rawErrTail.$chunk, -512);
                $append('stderr', $filter->push($chunk));
            }
        };

        try {
            $process = new Process(
                KubectlCommand::argv($target, $kubeconfig, $argv, $stdin !== null),
                sys_get_temp_dir(),
                ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'HOME' => sys_get_temp_dir()],
                $frames ?? $stdin,
                $timeoutSeconds + self::LOCAL_GRACE_SECONDS,
            );

            try {
                $process->start();
            } catch (\Throwable $e) {
                throw new ExecTransportException('kubectl could not be started: '.$e->getMessage(), 0, $e);
            }

            while ($process->isRunning()) {
                $append('stdout', $process->getIncrementalOutput());
                $process->clearOutput();
                $readErr($process);

                if ($onTick && $onTick() === false) {
                    $process->stop(1);
                    $stopped = true;
                    break;
                }

                try {
                    $process->checkTimeout();
                } catch (ProcessTimedOutException) {
                    $timedOut = true;
                    break;
                }

                // Frames wait for the sandbox: poll closely while they go
                usleep($frames !== null ? 1_000 : 20_000);
            }

            if (! $timedOut && ! $stopped) {
                $append('stdout', $process->getIncrementalOutput());
                $readErr($process);
            }
            if ($acks !== null) {
                // What looked like the start of a marker and was not
                $append('stderr', $filter->push($acks->flush()));
            }
            $append('stderr', $filter->finish());

            $exitCode = $timedOut ? 137 : ($stopped ? 130 : (int) ($process->getExitCode() ?? 1));
        } finally {
            KubectlCommand::removeKubeconfig($kubeconfig);
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        if (KubectlCommand::isKubectlError($rawErrTail === '' ? $stderr : $stderr."\n".$rawErrTail, $exitCode)) {
            throw new ExecTransportException(trim($stderr) ?: "kubectl exec failed (exit {$exitCode})");
        }

        // The in-sandbox timeout kills with SIGKILL: exit 137 (128 + 9) at or after the limit
        if (! $timedOut && in_array($exitCode, [124, 137], true) && $durationMs >= $timeoutSeconds * 1000) {
            $timedOut = true;
        }

        return new ExecResult($exitCode, $stdout, $stderr, $timedOut, $durationMs, $truncated);
    }
}
