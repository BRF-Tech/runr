<?php

namespace App\Triggers\Types\Sources;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Triggers\GatewayContext;
use Illuminate\Validation\Validator;

/**
 * A Kafka topic read through a consumer group (runtime/queue-consumer/sources/kafka): the
 * messages of a partition in order, the offset committed once a message is delivered or
 * dead-lettered. group_id defaults to runr.<project>.<trigger>; start_from applies to a new group.
 */
class KafkaTrigger extends BrokerTriggerType
{
    public const DURATION = 'regex:/^\d+(ms|s|m|h)?$/';

    public function key(): string
    {
        return 'kafka';
    }

    protected function configRules(): array
    {
        return [
            'brokers' => 'required|array|min:1|max:20',
            'brokers.*' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+:\d{1,5}$/'],
            'topic' => 'required|string|max:249|regex:/^[A-Za-z0-9._-]+$/',
            'group_id' => 'nullable|string|max:255',
            'start_from' => 'nullable|string|in:earliest,latest',
            'sasl' => 'nullable|array',
            'sasl.mechanism' => 'nullable|string|in:plain,scram-sha-256,scram-sha-512',
            'sasl.username' => 'nullable|required_with:config.sasl.mechanism|string|max:255',
            'max_wait' => ['nullable', 'string', self::DURATION],
        ] + self::tlsRules();
    }

    public function secretRules(): array
    {
        return ['sasl_password' => 'nullable|string|max:1024'];
    }

    public function normalize(array $config): array
    {
        $normalized = self::only($config, ['brokers' => 'list', 'topic', 'group_id', 'sasl.mechanism', 'sasl.username', 'max_wait']);
        $normalized['start_from'] = ($config['start_from'] ?? 'latest') === 'earliest' ? 'earliest' : 'latest';
        if ($tls = self::tlsConfig($config)) {
            $normalized['tls'] = $tls;
        }

        return self::keepDelivery($config, $normalized);
    }

    public function validate(Validator $validator, Project $project, array $config, ?ProjectTrigger $existing): void
    {
        if (! empty($config['sasl']['mechanism'])) {
            self::requireSecret($validator, $existing, 'sasl_password');
        }
    }

    protected function sourceConfig(ProjectTrigger $trigger, GatewayContext $context): array
    {
        $config = $trigger->config ?? [];

        return array_filter([
            'brokers' => array_values((array) ($config['brokers'] ?? [])),
            'topic' => (string) ($config['topic'] ?? ''),
            'group_id' => ($config['group_id'] ?? null) ?: self::defaultName($context, $trigger),
            'start_from' => $config['start_from'] ?? 'latest',
            'sasl' => isset($config['sasl']['mechanism']) ? ['mechanism' => $config['sasl']['mechanism'], 'username' => (string) ($config['sasl']['username'] ?? '')] : null,
            'tls' => $config['tls'] ?? null,
            'max_wait' => $config['max_wait'] ?? null,
        ], fn ($v) => $v !== null);
    }

    protected function sourceSecrets(ProjectTrigger $trigger): array
    {
        return isset(($trigger->config ?? [])['sasl']['mechanism']) ? ['sasl.password' => $trigger->secret('sasl_password')] : [];
    }

    public function fields(): array
    {
        return [
            $this->field('brokers', 'list', required: true),
            $this->field('topic', required: true),
            $this->field('group_id'),
            $this->field('start_from', 'select', default: 'latest', options: ['latest', 'earliest']),
            $this->field('sasl.mechanism', 'select', options: ['plain', 'scram-sha-256', 'scram-sha-512']),
            $this->field('sasl.username'),
            $this->field('sasl_password', secret: true),
            ...$this->tlsFields(),
        ];
    }
}
