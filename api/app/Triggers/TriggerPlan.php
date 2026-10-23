<?php

namespace App\Triggers;

use App\Models\ProjectTrigger;
use Illuminate\Support\Collection;

/**
 * What a project's enabled triggers deploy (KnativeManifestTrait) and reserve (ResourceReservation):
 *
 *  - exactly one enabled trigger, a schedule: the CronJob <slug>-trigger, no Knative Service —
 *    what a schedule project always ran as (single-trigger compatibility)
 *  - otherwise: the Knative Service <slug>; public when a trigger serves HTTP (http, workflow),
 *    cluster-local otherwise; plus the event gateway <slug>-qc when any trigger runs on it
 *    (queue, topic, webhook, platform_event, schedule next to other triggers, broker sources …).
 *    A schedule next to other triggers is a gateway source: each tick is a CloudEvent POSTed to
 *    the same service, no CronJob pod.
 *  - the gateway of a project whose only gateway trigger is one queue trigger with the default
 *    delivery settings keeps the queue consumer's env-only form (legacyQueue()), byte for byte
 *    what earlier versions applied.
 */
final class TriggerPlan
{
    /**
     * @param  Collection<int, ProjectTrigger>  $triggers  enabled triggers of a registered type, in order
     */
    public function __construct(
        public readonly Collection $triggers,
        private readonly TriggerTypes $types,
    ) {}

    /** The primary trigger: the first enabled one (null when every trigger is disabled). */
    public function primary(): ?ProjectTrigger
    {
        return $this->triggers->first();
    }

    public function has(string $type): bool
    {
        return $this->triggers->contains(fn (ProjectTrigger $t) => $t->type === $type);
    }

    /** @return Collection<int, ProjectTrigger> */
    public function ofType(string $type): Collection
    {
        return $this->triggers->filter(fn (ProjectTrigger $t) => $t->type === $type)->values();
    }

    /** The project runs as a CronJob: its only enabled trigger is a schedule. */
    public function cronJob(): bool
    {
        return $this->triggers->count() === 1 && $this->triggers->first()->type === 'schedule';
    }

    /** The project runs a Knative Service. */
    public function service(): bool
    {
        return ! $this->cronJob();
    }

    /** The Knative Service is public (an HTTP / workflow trigger), cluster-local otherwise. */
    public function public(): bool
    {
        return $this->triggers->contains(fn (ProjectTrigger $t) => $this->types->find($t->type)?->servesHttp());
    }

    /** @return Collection<int, ProjectTrigger> the triggers the event gateway runs */
    public function gatewayTriggers(): Collection
    {
        if ($this->cronJob()) {
            return collect();
        }

        return $this->triggers
            ->filter(fn (ProjectTrigger $t) => (bool) $this->types->find($t->type)?->runsOnGateway())
            ->values();
    }

    public function gateway(): bool
    {
        return $this->gatewayTriggers()->isNotEmpty();
    }

    /**
     * The single queue trigger of a gateway that keeps the queue consumer's env-only form
     * (STREAM / GROUP / SINK_URL), null otherwise.
     */
    public function legacyQueue(): ?ProjectTrigger
    {
        $gateway = $this->gatewayTriggers();
        if ($gateway->count() !== 1 || $this->has('workflow')) {
            return null;
        }

        $trigger = $gateway->first();
        if ($trigger->type !== 'queue' || ! empty($trigger->setting('delivery')) || $trigger->setting('start_from', 'earliest') !== 'earliest') {
            return null;
        }

        return $trigger;
    }

    /** Whether any trigger needs the region's Redis. */
    public function requiresRedis(): bool
    {
        return $this->triggers->contains(fn (ProjectTrigger $t) => (bool) $this->types->find($t->type)?->requiresRedis());
    }

    /**
     * The trigger the workload runs as for the runtime (RUNR_TRIGGER / the runtime manifest's
     * `triggers`): schedule for the CronJob, workflow, http for a public service, queue for a
     * service only the gateway calls.
     */
    public function runtimeTrigger(): string
    {
        return match (true) {
            $this->cronJob() => 'schedule',
            $this->has('workflow') => 'workflow',
            $this->public() => 'http',
            $this->gateway() => 'queue',
            default => 'http',
        };
    }

    /**
     * The runtime triggers every enabled trigger needs (each must be supported by the project's
     * runtime: an http runtime receives the gateway's events as HTTP requests).
     *
     * @return list<string>
     */
    public function runtimeTriggers(): array
    {
        if ($this->cronJob()) {
            return ['schedule'];
        }

        // Next to an HTTP trigger every event arrives as an HTTP request of the public service
        if ($this->public()) {
            return ['http'];
        }

        return $this->gateway() ? ['queue'] : ['http'];
    }

    /**
     * The trigger the capacity math (ResourceReservation) and metering (entry_point) count the
     * workload as: schedule (CronJob pod), queue (Knative Service + the gateway pod) or http.
     */
    public function capacityTrigger(): string
    {
        return match (true) {
            $this->cronJob() => 'schedule',
            $this->gateway() => 'queue',
            default => 'http',
        };
    }

    /** Metering entry point (UsageAggregator): the primary trigger's, within http | schedule | queue. */
    public function entryPoint(): string
    {
        if ($this->cronJob()) {
            return 'schedule';
        }
        $primary = $this->primary();
        $entry = $primary ? ($this->types->find($primary->type)?->entryPoint() ?? 'http') : 'http';

        // A schedule that is a gateway source counts like any event trigger
        return $entry === 'schedule' ? 'queue' : $entry;
    }
}
