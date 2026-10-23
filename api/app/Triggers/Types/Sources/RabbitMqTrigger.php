<?php

namespace App\Triggers\Types\Sources;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Triggers\GatewayContext;
use Illuminate\Validation\Validator;

/**
 * A RabbitMQ (AMQP 0-9-1) queue (runtime/queue-consumer/sources/rabbitmq): manual acks, a message
 * acknowledged once delivered or dead-lettered, requeued when the gateway stops before. The
 * connection URL (amqp[s]://user:password@host:port/vhost) is the trigger's secret `url`; with
 * declare the gateway declares the durable queue and its bindings.
 */
class RabbitMqTrigger extends BrokerTriggerType
{
    public function key(): string
    {
        return 'rabbitmq';
    }

    protected function configRules(): array
    {
        return [
            'queue' => 'required|string|max:255',
            'declare' => 'nullable|boolean',
            'bindings' => 'nullable|array|max:20',
            'bindings.*.exchange' => 'required|string|max:255',
            'bindings.*.routing_key' => 'nullable|string|max:255',
            'prefetch' => 'nullable|integer|min:1|max:1000',
        ] + self::tlsRules();
    }

    public function secretRules(): array
    {
        return ['url' => ['nullable', 'string', 'max:2000', 'regex:#^amqps?://#']];
    }

    public function normalize(array $config): array
    {
        $normalized = self::only($config, ['queue', 'declare' => 'bool', 'prefetch' => 'int']);
        if (! empty($config['declare']) && ! empty($config['bindings'])) {
            $normalized['bindings'] = array_values(array_map(fn ($b) => array_filter([
                'exchange' => (string) ($b['exchange'] ?? ''),
                'routing_key' => (string) ($b['routing_key'] ?? ''),
            ], fn ($v) => $v !== ''), (array) $config['bindings']));
        }
        if ($tls = self::tlsConfig($config)) {
            $normalized['tls'] = $tls;
        }

        return self::keepDelivery($config, $normalized);
    }

    public function validate(Validator $validator, Project $project, array $config, ?ProjectTrigger $existing): void
    {
        self::requireSecret($validator, $existing, 'url');
    }

    protected function sourceConfig(ProjectTrigger $trigger, GatewayContext $context): array
    {
        $config = $trigger->config ?? [];

        return array_filter([
            'queue' => (string) ($config['queue'] ?? ''),
            'declare' => (bool) ($config['declare'] ?? false),
            'bindings' => $config['bindings'] ?? null,
            'prefetch' => isset($config['prefetch']) ? (int) $config['prefetch'] : null,
            'tls' => $config['tls'] ?? null,
        ], fn ($v) => $v !== null);
    }

    protected function sourceSecrets(ProjectTrigger $trigger): array
    {
        return ['url' => $trigger->secret('url')];
    }

    public function fields(): array
    {
        return [
            $this->field('url', required: true, secret: true),
            $this->field('queue', required: true),
            $this->field('declare', 'boolean', default: false),
            $this->field('bindings', 'json'),
            $this->field('prefetch', 'integer'),
            ...$this->tlsFields(),
        ];
    }
}
