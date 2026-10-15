<?php

namespace Tests\Feature;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Listeners\CheckResourceAfterDeploy;
use App\Listeners\ProcessDeployment;
use App\Listeners\ProcessTeardown;
use Tests\TestCase;

/**
 * Every listener runs once per event: a listener registered both by event discovery and by
 * hand ran each deploy twice (two Knative revisions per deploy).
 */
class EventListenerRegistrationTest extends TestCase
{
    /** @return array<string, list<string>> event => listener class names */
    private function listenerClasses(): array
    {
        $result = [];
        foreach (app('events')->getRawListeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if (is_string($listener)) {
                    $result[$event][] = explode('@', $listener)[0];
                } elseif (is_array($listener) && is_string($listener[0] ?? null)) {
                    $result[$event][] = $listener[0];
                }
            }
        }

        return $result;
    }

    public function test_no_listener_is_registered_twice_for_an_event(): void
    {
        foreach ($this->listenerClasses() as $event => $classes) {
            $counts = array_filter(array_count_values($classes), fn ($n) => $n > 1);
            $this->assertSame([], $counts, "{$event} has listeners registered more than once");
        }
    }

    public function test_deployment_listeners_are_registered_exactly_once(): void
    {
        $listeners = $this->listenerClasses();

        $expected = [
            [DeploymentCreated::class, ProcessDeployment::class],
            [DeploymentCreated::class, CheckResourceAfterDeploy::class],
            [DeploymentStatusChanged::class, ProcessTeardown::class],
        ];

        foreach ($expected as [$event, $listener]) {
            $count = count(array_keys($listeners[$event] ?? [], $listener, true));
            $this->assertSame(1, $count, "{$listener} must listen to {$event} exactly once");
        }
    }
}
