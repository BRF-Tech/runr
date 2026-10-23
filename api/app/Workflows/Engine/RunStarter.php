<?php

namespace App\Workflows\Engine;

use App\Workflows\Dispatch\WorkflowDispatcher;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use App\Workflows\Models\WorkflowUsage;
use App\Workflows\Support\JsonValue;
use App\Workflows\Support\WorkflowEnvironments;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates runs: API starts, event / cron / queue triggers, step.invoke children and replays.
 * Applies the idempotency key (explicit, or the definition's `idempotency` expression of the
 * trigger event) and the keyed concurrency key, then schedules the first execution. A run belongs
 * to its workflow's environment (docs/workflows.md#environments).
 */
class RunStarter
{
    public function __construct(
        private readonly WorkflowDispatcher $dispatcher,
    ) {}

    /**
     * @param  array|object  $event  {name, data, id?, ts?}
     * @param array{
     *   source?: string, idempotency_key?: string|null, event_id?: string|null, started_by?: string|null,
     *   parent_run_id?: string|null, parent_step_id?: string|null, replay_of_run_id?: string|null,
     *   replay_from_step?: string|null, allow_inactive?: bool, copy_steps?: Collection<int, WorkflowStep>|array
     * } $options
     *
     * @throws WorkflowException
     */
    public function start(Workflow $workflow, array|object $event, array $options = []): StartResult
    {
        if (! ($options['allow_inactive'] ?? false) && ! $workflow->canStart()) {
            throw WorkflowException::conflict(__('workflows.inactive', ['name' => $workflow->name]), 'workflow_inactive');
        }

        $payload = $this->eventPayload($event, $options['event_id'] ?? null);
        $vars = ['event' => JsonValue::toArray($payload)];

        $key = $options['idempotency_key'] ?? null;
        if ($key === null && ($expr = $workflow->idempotencyExpression())) {
            $key = $this->keyFrom($expr, $vars, 'idem');
        }
        if ($key !== null) {
            $key = mb_substr((string) $key, 0, 200);
            $existing = WorkflowRun::where('workflow_id', $workflow->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                return new StartResult($existing, false);
            }
        }

        $concurrencyKey = ($expr = $workflow->concurrencyKeyExpression()) ? $this->keyFrom($expr, $vars, 'ck') : null;
        $copySteps = collect($options['copy_steps'] ?? []);

        try {
            $run = DB::transaction(function () use ($workflow, $payload, $options, $key, $concurrencyKey, $copySteps) {
                $run = WorkflowRun::create(self::environmentColumns($workflow) + [
                    'workflow_id' => $workflow->id,
                    'project_id' => $workflow->project_id,
                    'status' => WorkflowRun::QUEUED,
                    'trigger_source' => in_array($options['source'] ?? 'api', WorkflowRun::SOURCES, true) ? ($options['source'] ?? 'api') : 'api',
                    'event_id' => $options['event_id'] ?? null,
                    'event' => $payload,
                    'idempotency_key' => $key,
                    'concurrency_key' => $concurrencyKey,
                    'parent_run_id' => $options['parent_run_id'] ?? null,
                    'parent_step_id' => $options['parent_step_id'] ?? null,
                    'replay_of_run_id' => $options['replay_of_run_id'] ?? null,
                    'replay_from_step' => $options['replay_from_step'] ?? null,
                    'started_by' => isset($options['started_by']) ? (string) $options['started_by'] : null,
                    'timeout_at' => now()->addSeconds($workflow->runTimeoutSeconds()),
                ]);

                // Replay from a step: the steps before it are memoized in the new run
                foreach ($copySteps as $step) {
                    WorkflowStep::create([
                        'run_id' => $run->id,
                        'step_key' => $step->step_key,
                        'name' => $step->name,
                        'type' => $step->type,
                        'status' => $step->status,
                        'position' => $step->position,
                        'attempt' => $step->attempt,
                        'max_attempts' => $step->max_attempts,
                        'input' => $step->input,
                        'output' => $step->output,
                        'error' => $step->error,
                        'attempts' => $step->attempts,
                        'started_at' => $step->started_at,
                        'ended_at' => $step->ended_at,
                        'duration_ms' => $step->duration_ms,
                    ]);
                }

                if ($copySteps->isNotEmpty()) {
                    $run->update(['steps_count' => $copySteps->count()]);
                }

                return $run;
            });
        } catch (QueryException $e) {
            // Same idempotency key created concurrently: answer with that run
            if ($key !== null && ($existing = WorkflowRun::where('workflow_id', $workflow->id)->where('idempotency_key', $key)->first())) {
                return new StartResult($existing, false);
            }
            throw $e;
        }

        $environmentId = $workflow->environment_id !== null ? (string) $workflow->environment_id : null;
        DB::afterCommit(fn () => WorkflowUsage::record($workflow->project_id, $workflow->id, runs: 1, environmentId: $environmentId));
        $this->dispatcher->dispatch($run->id);

        return new StartResult($run, true);
    }

    /**
     * The environment of a run: its workflow's (docs/workflows.md#environments) — the id, and the
     * name the run's waits match events by.
     *
     * @return array<string, string|null>
     */
    public static function environmentColumns(Workflow $workflow): array
    {
        if (! WorkflowEnvironments::ready()) {
            return [];
        }

        return [
            'environment_id' => $workflow->environment_id,
            'environment' => $workflow->environmentName(),
        ];
    }

    /** {id, name, data, ts} with defaults; validates the size limit. */
    public function eventPayload(array|object $event, ?string $eventId = null): object
    {
        $event = is_object($event) ? (array) $event : $event;

        $data = $event['data'] ?? null;
        if ($data === null || $data === []) {
            $data = new \stdClass;
        }

        $payload = (object) [
            'id' => $eventId ?? (isset($event['id']) && is_string($event['id']) ? $event['id'] : (string) Str::ulid()),
            'name' => (string) ($event['name'] ?? 'runr/api.start'),
            'data' => $data,
            'ts' => isset($event['ts']) && is_numeric($event['ts']) ? (int) $event['ts'] : (int) floor(microtime(true) * 1000),
        ];

        if (Protocol::size($payload) > (int) config('workflows.limits.max_event_bytes', 262144)) {
            throw WorkflowException::tooLarge(__('workflows.event_too_large', ['max' => config('workflows.limits.max_event_bytes')]));
        }

        return $payload;
    }

    /** Key from an expression of the trigger event; null when the expression yields null. */
    private function keyFrom(string $expression, array $vars, string $prefix): ?string
    {
        try {
            $value = Expression::evaluate($expression, $vars);
        } catch (ExpressionException) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        $raw = is_scalar($value) ? (string) $value : Protocol::encode($value);

        return strlen($raw) <= 150 ? "{$prefix}:{$raw}" : "{$prefix}#".hash('sha256', $raw);
    }
}
