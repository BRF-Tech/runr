<?php

namespace App\Triggers\Types;

use App\Triggers\TriggerType;

/** HTTP requests to the project's public URL (subdomain, gateway route, custom domains). */
class HttpTrigger extends TriggerType
{
    public function key(): string
    {
        return 'http';
    }

    public function normalize(array $config): array
    {
        return [];
    }

    public function servesHttp(): bool
    {
        return true;
    }

    public function runtimeTrigger(): string
    {
        return 'http';
    }

    public function maxPerProject(): ?int
    {
        return 1;
    }

    public function entryPoint(): string
    {
        return 'http';
    }
}
