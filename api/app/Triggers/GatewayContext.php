<?php

namespace App\Triggers;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Services\Manifests\QueueConsumerManifests;
use App\Triggers\Inbox\InboxToken;
use App\Workflows\Deploy\QueueBridge;

/**
 * What a trigger type needs to render its event gateway entry (TriggerType::gatewayEntry()): the
 * project, its region namespace, the function's cluster-local URL, the region's Redis and the
 * inbox URL of the RunR API. Everything here ends up in the gateway's config Secret, never in a
 * Deployment spec.
 */
final class GatewayContext
{
    public function __construct(
        public readonly Project $project,
        public readonly string $namespace,
        public readonly string $serviceName,
        public readonly int $timeoutSeconds = 30,
    ) {}

    public function region(): ?KnativeRegion
    {
        return $this->project->knativeRegion;
    }

    /** The function's cluster-local URL: the gateway's default sink. */
    public function sinkUrl(): string
    {
        return 'http://'.$this->serviceName.'.'.$this->namespace.'.svc.cluster.local';
    }

    /** redis://[:password@]host:port of the region (the address as the queue consumer gets it). */
    public function redisAddress(): string
    {
        $region = $this->region();
        $raw = (string) ($region->redis_address ?? 'localhost:6379');
        $scheme = str_starts_with($raw, 'rediss://') ? 'rediss' : 'redis';
        $host = preg_replace('#^rediss?://#', '', $raw);
        $password = $region->redis_password ?? null;

        return $password ? "{$scheme}://:{$password}@{$host}" : "{$scheme}://{$host}";
    }

    public function redisPassword(): string
    {
        return (string) ($this->region()->redis_password ?? '');
    }

    /** A stream name as the project's organization sees it (tenant suffix in a multi-tenant install). */
    public function stream(string $name): string
    {
        return QueueConsumerManifests::resolveStreamName($name);
    }

    /** Consumer group of a trigger that reads a shared stream (topic subscription). */
    public function subscriberGroup(ProjectTrigger $trigger): string
    {
        return 'sub.'.$this->project->slug.'.'.$trigger->name;
    }

    /** Redis Stream source settings of the region's Redis. */
    public function redisSource(array $settings): array
    {
        return ['address' => $this->redisAddress(), 'password' => $this->redisPassword()] + $settings;
    }

    /** A dead-letter stream on the region's Redis. */
    public function redisDeadLetter(string $stream, array $meta = []): array
    {
        return array_filter([
            'kind' => 'redis-stream',
            'address' => $this->redisAddress(),
            'password' => $this->redisPassword(),
            'stream' => $stream,
            'max_len' => (int) config('triggers.dead_letter_max_len', 10000),
            'meta' => $meta ?: null,
        ], fn ($v) => $v !== null);
    }

    /** Base URL of the project's inbox on the RunR API (claim / ack), with its path token. */
    public function inboxUrl(): string
    {
        $context = TriggerContexts::current();

        return QueueBridge::apiBase($this->project)
            .'/api/internal/triggers/'.rawurlencode($context).'/'.$this->project->id.'/'
            .InboxToken::for($context, $this->project->id);
    }
}
