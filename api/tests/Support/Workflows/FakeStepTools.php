<?php

namespace Tests\Support\Workflows;

use Closure;
use Throwable;

/**
 * The `step` object of FakeWorkflowFunction handlers: deterministic replay over the memoized
 * steps of the request. The first step without a memoized result runs (step.run) or is
 * reported (sleep / wait / invoke / send) and interrupts the handler with that op.
 */
class FakeStepTools
{
    /** @var array<string, int> */
    private array $seen = [];

    public function __construct(private readonly object $request, private readonly FakeWorkflowFunction $fn) {}

    public function attempt(): int
    {
        return (int) ($this->request->run->attempt ?? 0);
    }

    public function run(string $id, Closure $callback, ?string $retryAfter = null): mixed
    {
        $key = $this->key($id);

        if ($memo = $this->memo($key)) {
            return $this->unwrap($memo, $key);
        }

        $this->fn->executions[$key] = ($this->fn->executions[$key] ?? 0) + 1;

        try {
            $output = $callback($this->attempt());
        } catch (FakeNonRetriable $e) {
            throw new FakeInterrupt(['op' => 'step_failed', 'id' => $key, 'name' => $id, 'retriable' => false,
                'error' => ['name' => 'NonRetriableError', 'message' => $e->getMessage()]]);
        } catch (Throwable $e) {
            $op = ['op' => 'step_failed', 'id' => $key, 'name' => $id,
                'error' => ['name' => class_basename($e), 'message' => $e->getMessage()]];
            if ($retryAfter !== null) {
                $op['retry_after'] = $retryAfter;
            }
            throw new FakeInterrupt($op);
        }

        throw new FakeInterrupt(['op' => 'step_completed', 'id' => $key, 'name' => $id, 'output' => $output, 'duration_ms' => 5]);
    }

    public function sleep(string $id, string $duration): void
    {
        $key = $this->key($id);
        if ($this->memo($key)) {
            return;
        }

        throw new FakeInterrupt(['op' => 'sleep', 'id' => $key, 'name' => $id, 'duration' => $duration]);
    }

    public function sleepUntil(string $id, string $until): void
    {
        $key = $this->key($id);
        if ($this->memo($key)) {
            return;
        }

        throw new FakeInterrupt(['op' => 'sleep', 'id' => $key, 'name' => $id, 'until' => $until]);
    }

    public function waitForEvent(string $id, string $event, ?string $match = null, ?string $timeout = null, ?string $if = null): mixed
    {
        $key = $this->key($id);
        if ($memo = $this->memo($key)) {
            return $this->unwrap($memo, $key);
        }

        throw new FakeInterrupt(array_filter([
            'op' => 'wait_for_event', 'id' => $key, 'name' => $id, 'event' => $event,
            'match' => $match, 'timeout' => $timeout, 'if' => $if,
        ], fn ($v) => $v !== null));
    }

    public function invoke(string $id, string $workflow, array $data = [], ?string $timeout = null): mixed
    {
        $key = $this->key($id);
        if ($memo = $this->memo($key)) {
            return $this->unwrap($memo, $key);
        }

        throw new FakeInterrupt(array_filter([
            'op' => 'invoke', 'id' => $key, 'name' => $id, 'workflow' => $workflow,
            'data' => (object) $data, 'timeout' => $timeout,
        ], fn ($v) => $v !== null));
    }

    public function sendEvent(string $id, array $events): mixed
    {
        $key = $this->key($id);
        if ($memo = $this->memo($key)) {
            return $this->unwrap($memo, $key);
        }

        throw new FakeInterrupt(['op' => 'send_event', 'id' => $key, 'name' => $id, 'events' => $events]);
    }

    private function key(string $id): string
    {
        $n = $this->seen[$id] ?? 0;
        $this->seen[$id] = $n + 1;

        return $n === 0 ? $id : "{$id}:{$n}";
    }

    private function memo(string $key): ?object
    {
        $steps = $this->request->steps ?? null;

        return is_object($steps) && isset($steps->{$key}) ? $steps->{$key} : null;
    }

    private function unwrap(object $memo, string $key): mixed
    {
        if (($memo->status ?? null) === 'failed') {
            throw new FakeStepFailed("step {$key} failed: ".($memo->error->message ?? ''));
        }

        return $memo->output ?? null;
    }
}
