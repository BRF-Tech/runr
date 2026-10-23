<?php

namespace App\Triggers\Types\Sources;

use App\Models\ProjectTrigger;
use App\Triggers\GatewayContext;

/**
 * An MQTT topic filter (runtime/queue-consumer/sources/mqtt). QoS 1 / 2 with a persistent session
 * (clean_session false): messages queued while the gateway was disconnected arrive, each
 * acknowledged once delivered or dead-lettered — at least once. QoS 0 is at most once. The client
 * id defaults to runr-<project>-<trigger>; shared_group subscribes to $share/<group>/<topic>.
 */
class MqttTrigger extends BrokerTriggerType
{
    public function key(): string
    {
        return 'mqtt';
    }

    protected function configRules(): array
    {
        return [
            'broker' => ['required', 'string', 'max:1000', 'regex:#^(tcp|ssl|tls|ws|wss|mqtt|mqtts)://#'],
            'topic' => 'required|string|max:65535',
            'qos' => 'nullable|integer|in:0,1,2',
            'client_id' => 'nullable|string|max:23|regex:/^[A-Za-z0-9_-]+$/',
            'clean_session' => 'nullable|boolean',
            'username' => 'nullable|string|max:255',
            'shared_group' => 'nullable|string|max:255|regex:/^[A-Za-z0-9_-]+$/',
        ] + self::tlsRules();
    }

    public function secretRules(): array
    {
        return ['password' => 'nullable|string|max:1024'];
    }

    public function normalize(array $config): array
    {
        $normalized = self::only($config, ['broker', 'topic', 'client_id', 'username', 'shared_group']);
        $normalized['qos'] = in_array((int) ($config['qos'] ?? 1), [0, 1, 2], true) ? (int) ($config['qos'] ?? 1) : 1;
        $normalized['clean_session'] = (bool) ($config['clean_session'] ?? false);
        if ($tls = self::tlsConfig($config)) {
            $normalized['tls'] = $tls;
        }

        return self::keepDelivery($config, $normalized);
    }

    protected function sourceConfig(ProjectTrigger $trigger, GatewayContext $context): array
    {
        $config = $trigger->config ?? [];

        return array_filter([
            'broker' => (string) ($config['broker'] ?? ''),
            'topic' => (string) ($config['topic'] ?? ''),
            'qos' => (int) ($config['qos'] ?? 1),
            // MQTT 3.1 caps client ids at 23 bytes: a hash keeps the default short and stable
            'client_id' => ($config['client_id'] ?? null) ?: 'runr-'.substr(hash('sha256', $context->project->id.'/'.$trigger->name), 0, 18),
            'clean_session' => (bool) ($config['clean_session'] ?? false),
            'username' => $config['username'] ?? null,
            'shared_group' => $config['shared_group'] ?? null,
            'tls' => $config['tls'] ?? null,
        ], fn ($v) => $v !== null);
    }

    protected function sourceSecrets(ProjectTrigger $trigger): array
    {
        return ['password' => $trigger->secret('password')];
    }

    public function fields(): array
    {
        return [
            $this->field('broker', required: true),
            $this->field('topic', required: true),
            $this->field('qos', 'select', default: 1, options: ['0', '1', '2']),
            $this->field('client_id'),
            $this->field('clean_session', 'boolean', default: false),
            $this->field('username'),
            $this->field('password', secret: true),
            $this->field('shared_group'),
            ...$this->tlsFields(),
        ];
    }
}
