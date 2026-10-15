<?php

namespace Tests\Support\Workflows;

use App\Workflows\Dispatch\WorkflowDispatcher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/** Records the engine's dispatches; tests drain them with the executor. */
class RecordingDispatcher implements WorkflowDispatcher
{
    /** @var list<array{0: string, 1: CarbonImmutable|null}> */
    public array $queue = [];

    /** @var list<array{0: string, 1: CarbonImmutable|null}> every dispatch ever made */
    public array $history = [];

    public function dispatch(string $runId, ?DateTimeInterface $at = null): void
    {
        $entry = [$runId, $at ? CarbonImmutable::instance($at) : null];
        $this->queue[] = $entry;
        $this->history[] = $entry;
    }

    /** Next dispatch due at $now (FIFO), removed from the queue. */
    public function takeDue(CarbonInterface $now): ?string
    {
        foreach ($this->queue as $i => [$id, $at]) {
            if ($at === null || $at->lessThanOrEqualTo($now)) {
                unset($this->queue[$i]);
                $this->queue = array_values($this->queue);

                return $id;
            }
        }

        return null;
    }

    /** Delayed dispatches still waiting for their time. */
    public function delayed(): array
    {
        return array_values(array_filter($this->queue, fn ($e) => $e[1] !== null));
    }

    public function clear(): void
    {
        $this->queue = [];
    }
}
