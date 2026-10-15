<?php

namespace App\Services\Concerns;

use App\Events\PlatformEvent;

trait BroadcastsModelEvents
{
    protected function broadcastModel(string $topic, array $payload): void
    {
        event(new PlatformEvent($topic, $payload));
    }
}
