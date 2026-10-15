<?php

namespace App\Workflows\Engine;

use App\Workflows\Models\WorkflowEvent;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use App\Workflows\Models\WorkflowTrigger;
use App\Workflows\Support\JsonValue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Ingests events (events API, step.sendEvent, queue bridge, test events). For each event, in
 * this order:
 *   1. cancel_on: active runs of workflows that cancel on this event (and match) are cancelled;
 *   2. waits: step.waitForEvent steps waiting for this event name whose match / if holds are
 *      resolved with the event and their runs resume;
 *   3. triggers: workflows with an event trigger for this name (and a true `if`) start a run.
 * An event with an `id` that was already received is not processed again (deduplication).
 */
class EventIngestor
{
    /** Event names under this prefix are produced by the engine itself. */
    public const RESERVED_PREFIX = 'runr/';

    private const WAIT_SCAN_LIMIT = 1000;

    private const CANCEL_SCAN_LIMIT = 1000;

    public function __construct(
        private readonly RunStarter $starter,
        private readonly RunControl $control,
    ) {}

    /**
     * @param  list<mixed>  $events
     * @return list<array{id: string, name: string, duplicate: bool, runs: list<string>, resumed: int, cancelled: int}>
     *
     * @throws WorkflowException before anything is stored when one event is invalid
     */
    public function ingest(array $events, string $source = 'api', ?string $projectId = null, ?string $sentBy = null): array
    {
        $max = (int) config('workflows.limits.max_events_per_request', 100);
        if ($events === []) {
            throw WorkflowException::invalid(__('workflows.events_empty'), 'events_empty');
        }
        if (count($events) > $max) {
            throw WorkflowException::invalid(__('workflows.events_too_many', ['max' => $max]), 'events_too_many');
        }

        $normalized = [];
        foreach ($events as $i => $raw) {
            $normalized[] = $this->normalize($raw, $i, $source);
        }

        $results = [];
        foreach ($normalized as $event) {
            $results[] = $this->ingestOne($event, $source, $projectId, $sentBy);
        }

        return $results;
    }

    /**
     * @return array{name: string, data: mixed, external_id: string|null, ts: int}
     *
     * @throws WorkflowException
     */
    public function normalize(mixed $raw, int $index = 0, string $source = 'api'): array
    {
        if (is_object($raw)) {
            $raw = (array) $raw;
        }
        if (! is_array($raw)) {
            throw WorkflowException::invalid(__('workflows.event_invalid', ['index' => $index]), 'event_invalid');
        }

        $name = $raw['name'] ?? null;
        if (! is_string($name) || ! preg_match(Definition::EVENT_PATTERN, $name)) {
            throw WorkflowException::invalid(__('workflows.event_name_invalid', ['index' => $index]), 'event_name_invalid');
        }
        if (str_starts_with($name, self::RESERVED_PREFIX) && ! in_array($source, ['cron', 'queue'], true)) {
            throw WorkflowException::invalid(__('workflows.event_name_reserved', ['prefix' => self::RESERVED_PREFIX]), 'event_name_reserved');
        }

        $data = $raw['data'] ?? null;
        if ($data === null || $data === []) {
            $data = new \stdClass;
        } elseif (is_array($data) && array_is_list($data)) {
            throw WorkflowException::invalid(__('workflows.event_data_object', ['index' => $index]), 'event_data_invalid');
        } elseif (! is_array($data) && ! is_object($data)) {
            throw WorkflowException::invalid(__('workflows.event_data_object', ['index' => $index]), 'event_data_invalid');
        }

        $externalId = $raw['id'] ?? null;
        if ($externalId !== null && (! is_string($externalId) || $externalId === '' || strlen($externalId) > 200)) {
            throw WorkflowException::invalid(__('workflows.event_id_invalid', ['index' => $index]), 'event_id_invalid');
        }

        $ts = $raw['ts'] ?? null;
        $ts = is_numeric($ts) && (int) $ts > 0 ? (int) $ts : (int) floor(microtime(true) * 1000);

        if (Protocol::size(['name' => $name, 'data' => $data]) > (int) config('workflows.limits.max_event_bytes', 262144)) {
            throw WorkflowException::tooLarge(__('workflows.event_too_large', ['max' => config('workflows.limits.max_event_bytes')]));
        }

        return ['name' => $name, 'data' => $data, 'external_id' => $externalId, 'ts' => $ts];
    }

    private function ingestOne(array $e, string $source, ?string $projectId, ?string $sentBy): array
    {
        if ($e['external_id'] !== null && ($dup = WorkflowEvent::where('external_id', $e['external_id'])->first())) {
            return $this->result($dup, true, [], 0, 0);
        }

        try {
            $event = WorkflowEvent::create([
                'name' => $e['name'],
                'data' => $e['data'],
                'external_id' => $e['external_id'],
                'source' => in_array($source, WorkflowEvent::SOURCES, true) ? $source : 'api',
                'project_id' => $projectId,
                'sent_by' => $sentBy,
                'ts' => $e['ts'],
            ]);
        } catch (QueryException $ex) {
            if ($e['external_id'] !== null && ($dup = WorkflowEvent::where('external_id', $e['external_id'])->first())) {
                return $this->result($dup, true, [], 0, 0);
            }
            throw $ex;
        }

        $payload = (object) $event->payload();
        $async = JsonValue::toArray($payload);

        $cancelled = $this->applyCancellations($event, $async);
        $resumed = $this->resumeWaits($event, $payload, $async);
        $runs = $this->startTriggered($event, $payload, $async, $source, $sentBy);

        $event->forceFill([
            'runs_started' => count($runs),
            'waits_resumed' => $resumed,
            'runs_cancelled' => $cancelled,
        ])->save();

        return $this->result($event, false, $runs, $resumed, $cancelled);
    }

    private function applyCancellations(WorkflowEvent $event, array $async): int
    {
        $count = 0;

        $triggers = WorkflowTrigger::where('type', WorkflowTrigger::TYPE_CANCEL)
            ->where('event', $event->name)
            ->get();

        foreach ($triggers as $trigger) {
            $runs = WorkflowRun::where('workflow_id', $trigger->workflow_id)
                ->whereIn('status', WorkflowRun::ACTIVE)
                ->orderBy('created_at')
                ->limit(self::CANCEL_SCAN_LIMIT)
                ->get();

            foreach ($runs as $run) {
                if ($run->event_id === $event->id) {
                    continue; // the event that started a run does not cancel it
                }
                $vars = ['event' => JsonValue::toArray($run->event), 'async' => $async];
                if ($trigger->expression && ! Expression::test($trigger->expression, $vars)) {
                    continue;
                }
                if ($this->control->cancel($run, "Cancelled by event \"{$event->name}\" ({$event->id})")) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function resumeWaits(WorkflowEvent $event, object $payload, array $async): int
    {
        $count = 0;

        $steps = WorkflowStep::where('status', WorkflowStep::WAITING)
            ->where('type', WorkflowStep::TYPE_WAIT)
            ->where('wait_event', $event->name)
            ->orderBy('created_at')
            ->limit(self::WAIT_SCAN_LIMIT)
            ->get();

        $runs = WorkflowRun::whereIn('id', $steps->pluck('run_id')->unique())->get()->keyBy('id');

        foreach ($steps as $step) {
            $run = $runs->get($step->run_id);
            if (! $run || $run->isTerminal()) {
                continue;
            }
            $vars = ['event' => JsonValue::toArray($run->event), 'async' => $async];
            if ($step->wait_expression && ! Expression::test($step->wait_expression, $vars)) {
                continue;
            }
            if ($this->control->resolveWait($step, $payload)) {
                $count++;
            }
        }

        return $count;
    }

    /** @return list<string> started run ids */
    private function startTriggered(WorkflowEvent $event, object $payload, array $async, string $source, ?string $sentBy): array
    {
        $runs = [];
        $started = [];

        $triggers = WorkflowTrigger::where('type', WorkflowTrigger::TYPE_EVENT)
            ->where('event', $event->name)
            ->with('workflow')
            ->get();

        foreach ($triggers as $trigger) {
            $workflow = $trigger->workflow;
            if (! $workflow || ! $workflow->canStart() || isset($started[$workflow->id])) {
                continue;
            }
            if ($trigger->expression && ! Expression::test($trigger->expression, ['event' => $async])) {
                continue;
            }

            try {
                $result = $this->starter->start($workflow, $payload, [
                    'source' => match ($source) {
                        'queue' => 'queue', 'cron' => 'cron', 'test' => 'test', default => 'event'
                    },
                    'event_id' => $event->id,
                    'started_by' => $sentBy,
                ]);
            } catch (WorkflowException $e) {
                Log::warning('Workflow trigger did not start a run', ['workflow' => $workflow->id, 'event' => $event->id, 'error' => $e->getMessage()]);

                continue;
            }

            $started[$workflow->id] = true;
            if ($result->created) {
                $runs[] = $result->run->id;
            }
        }

        return $runs;
    }

    private function result(WorkflowEvent $event, bool $duplicate, array $runs, int $resumed, int $cancelled): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'duplicate' => $duplicate,
            'runs' => $runs,
            'resumed' => $resumed,
            'cancelled' => $cancelled,
        ];
    }
}
