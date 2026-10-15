<?php

namespace Tests\Support;

use App\Services\Sandboxes\Exec\ExecResult;
use App\Services\Sandboxes\Exec\ExecTransport;
use App\Services\Sandboxes\Exec\ExecTransportException;
use App\Services\Sandboxes\Exec\PodTarget;

/**
 * ExecTransport of the Sandbox API tests: records every command and answers it from programmed
 * responses, matched on the shell script (argv[2] of /bin/sh -c …) or the user argv. A response
 * streams its `chunks` through onOutput like kubectl would; the default answer is exit 0 with no
 * output. `fs` is a tiny in-memory file system used by the tar round-trip tests.
 */
class FakeSandboxExecTransport implements ExecTransport
{
    /** @var list<array{target: PodTarget, argv: list<string>, stdin: ?string, timeout: int}> */
    public array $calls = [];

    /** @var list<array{0: callable, 1: callable|array}> */
    private array $handlers = [];

    public bool $failAll = false;

    /**
     * @param  callable(array $argv, ?string $stdin): bool  $matcher
     * @param  callable(array $argv, ?string $stdin): (array|ExecResult)|array  $response
     *                                                                                     array: {stdout?, stderr?, exit?, timed_out?, chunks?: list<array{0: 'stdout'|'stderr', 1: string}>, ticks?: int}
     */
    public function on(callable $matcher, callable|array $response): self
    {
        $this->handlers[] = [$matcher, $response];

        return $this;
    }

    /** Respond to commands whose wrapper script or argv contains $needle. */
    public function whenContains(string $needle, callable|array $response): self
    {
        return $this->on(fn (array $argv) => str_contains(implode("\0", $argv), $needle), $response);
    }

    public function exec(
        PodTarget $target,
        array $argv,
        ?string $stdin = null,
        int $timeoutSeconds = 60,
        ?callable $onOutput = null,
        int $maxOutputBytes = 10485760,
        ?callable $onTick = null,
    ): ExecResult {
        $this->calls[] = ['target' => $target, 'argv' => $argv, 'stdin' => $stdin, 'timeout' => $timeoutSeconds];

        if ($this->failAll) {
            throw new ExecTransportException('Error from server (NotFound): pods "'.$target->pod.'" not found');
        }

        foreach (array_reverse($this->handlers) as [$matcher, $response]) {
            if (! $matcher($argv, $stdin)) {
                continue;
            }
            $value = is_callable($response) ? $response($argv, $stdin) : $response;
            if ($value instanceof ExecResult) {
                return $value;
            }

            return $this->play($value, $onOutput, $onTick, $maxOutputBytes);
        }

        return new ExecResult(0);
    }

    private function play(array $r, ?callable $onOutput, ?callable $onTick, int $max): ExecResult
    {
        $chunks = $r['chunks'] ?? array_values(array_filter([
            isset($r['stdout']) && $r['stdout'] !== '' ? ['stdout', $r['stdout']] : null,
            isset($r['stderr']) && $r['stderr'] !== '' ? ['stderr', $r['stderr']] : null,
        ]));

        $stdout = '';
        $stderr = '';
        $truncated = false;
        foreach ($chunks as [$type, $data]) {
            if ($onTick && $onTick() === false) {
                return new ExecResult(130, $stdout, $stderr, false, 1, $truncated);
            }
            $current = $type === 'stdout' ? strlen($stdout) : strlen($stderr);
            if ($current + strlen($data) > $max) {
                $data = substr($data, 0, max(0, $max - $current));
                $truncated = true;
            }
            if ($type === 'stdout') {
                $stdout .= $data;
            } else {
                $stderr .= $data;
            }
            if ($onOutput && $data !== '') {
                $onOutput($type, $data);
            }
        }

        return new ExecResult((int) ($r['exit'] ?? 0), $stdout, $stderr, (bool) ($r['timed_out'] ?? false), (int) ($r['duration_ms'] ?? 5), $truncated);
    }

    /** The wrapper script of a /bin/sh -c call. */
    public static function script(array $argv): string
    {
        return ($argv[0] ?? '') === '/bin/sh' && ($argv[1] ?? '') === '-c' ? (string) ($argv[2] ?? '') : '';
    }

    /** User argv after the ExecScript::command wrapper ('runr' is $0). */
    public static function userArgv(array $argv): array
    {
        $pos = array_search('runr', $argv, true);

        return $pos === false ? [] : array_slice($argv, $pos + 1);
    }

    /** Calls whose script or argv contains $needle. */
    public function callsContaining(string $needle): array
    {
        return array_values(array_filter($this->calls, fn ($c) => str_contains(implode("\0", $c['argv']), $needle)));
    }
}
