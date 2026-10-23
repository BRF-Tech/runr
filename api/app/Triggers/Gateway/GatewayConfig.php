<?php

namespace App\Triggers\Gateway;

use App\Models\ProjectTrigger;
use App\Triggers\GatewayContext;
use App\Triggers\TriggerTypes;
use App\Triggers\Types\GatewayTriggerType;
use Illuminate\Support\Collection;

/**
 * gateway.json of a project's event gateway (runtime/queue-consumer, GATEWAY_CONFIG): the
 * function's cluster-local URL as the default sink and one entry per gateway trigger — the Go
 * source kind and its settings from the trigger type (TriggerType::gatewayEntry()), the delivery
 * settings (concurrency, retries, backoff, timeout) and the dead letter. It carries credentials
 * (Redis, inbox token, broker secrets), so it only ever goes into the Secret <slug>-qc-config.
 */
final class GatewayConfig
{
    public const VERSION = 1;

    /** Template annotation of the gateway Deployment: hash() of its gateway.json. */
    public const HASH_ANNOTATION = 'runr.dev/gateway-config';

    public function __construct(private TriggerTypes $types) {}

    /**
     * @param  Collection<int, ProjectTrigger>  $triggers  the plan's gateway triggers
     * @return array<string, mixed>
     */
    public function build(GatewayContext $context, Collection $triggers): array
    {
        $entries = [];
        foreach ($triggers as $trigger) {
            $type = $this->types->find($trigger->type);
            $entry = $type?->gatewayEntry($trigger, $context);
            if ($entry === null) {
                continue;
            }

            $delivery = GatewayTriggerType::delivery($trigger);
            $entries[] = array_filter([
                'name' => $trigger->name,
                'type' => $trigger->type,
                'kind' => $entry['kind'],
                'sink' => $entry['sink'] ?? null,
                'concurrency' => $delivery['concurrency'],
                'max_retries' => $delivery['max_retries'],
                'retry_backoff' => $delivery['retry_backoff_seconds'].'s',
                'retry_backoff_max' => $delivery['retry_backoff_max_seconds'].'s',
                'delivery_timeout' => $context->timeoutSeconds.'s',
                'dead_letter' => $entry['dead_letter'] ?? null,
                'source' => (object) ($entry['source'] ?? []),
            ], fn ($v) => $v !== null);
        }

        return [
            'version' => self::VERSION,
            'project' => $context->project->id,
            'sink' => $context->sinkUrl(),
            'delivery_timeout' => $context->timeoutSeconds.'s',
            'triggers' => $entries,
        ];
    }

    /** The JSON as the Secret stores it. */
    public function render(GatewayContext $context, Collection $triggers): string
    {
        return self::encode($this->build($context, $triggers));
    }

    /** gateway.json text of a config (build(), or one read back from the Secret and changed). */
    public static function encode(array|\stdClass $config): string
    {
        return (string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * The gateway Deployment's template annotation runr.dev/gateway-config: the config's content
     * hash, so a changed config rolls the pod (it reads the file at start).
     */
    public static function hash(string $json): string
    {
        return substr(hash('sha256', $json), 0, 16);
    }
}
