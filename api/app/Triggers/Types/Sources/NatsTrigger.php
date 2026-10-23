<?php

namespace App\Triggers\Types\Sources;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Triggers\GatewayContext;
use Illuminate\Validation\Validator;

/**
 * NATS subjects (runtime/queue-consumer/sources/nats). With a JetStream stream: a durable pull
 * consumer (runr-<project>-<trigger> unless named), each message acknowledged once delivered or
 * dead-lettered — at least once. Without one: a core NATS queue subscription, at most once (a
 * message that arrives while the gateway is down is lost).
 */
class NatsTrigger extends BrokerTriggerType
{
    public function key(): string
    {
        return 'nats';
    }

    protected function configRules(): array
    {
        return [
            'url' => ['required', 'string', 'max:1000', 'regex:#^(nats|tls|ws|wss)://#'],
            'subject' => 'required|string|max:255|regex:/^[^\s]+$/',
            'stream' => 'nullable|string|max:255|regex:/^[A-Za-z0-9_-]+$/',
            'durable' => 'nullable|string|max:255|regex:/^[A-Za-z0-9_-]+$/',
            'deliver' => 'nullable|string|in:new,all',
            'queue_group' => 'nullable|string|max:255|regex:/^[A-Za-z0-9._-]+$/',
            'ack_wait' => ['nullable', 'string', KafkaTrigger::DURATION],
            'auth' => 'nullable|array',
            'auth.mode' => 'nullable|string|in:none,user,token,nkey,creds',
            'auth.user' => 'nullable|string|max:255',
        ] + self::tlsRules();
    }

    public function secretRules(): array
    {
        return [
            'password' => 'nullable|string|max:1024',
            'token' => 'nullable|string|max:4096',
            'nkey_seed' => 'nullable|string|max:1024',
            'creds' => 'nullable|string|max:16384',
        ];
    }

    public function normalize(array $config): array
    {
        $normalized = self::only($config, ['url', 'subject', 'stream', 'durable', 'queue_group', 'ack_wait', 'auth.mode', 'auth.user']);
        $normalized['deliver'] = ($config['deliver'] ?? 'new') === 'all' ? 'all' : 'new';
        if ($tls = self::tlsConfig($config)) {
            $normalized['tls'] = $tls;
        }

        return self::keepDelivery($config, $normalized);
    }

    public function validate(Validator $validator, Project $project, array $config, ?ProjectTrigger $existing): void
    {
        $mode = $config['auth']['mode'] ?? 'none';
        $needs = ['user' => 'password', 'token' => 'token', 'nkey' => 'nkey_seed', 'creds' => 'creds'][$mode] ?? null;
        if ($needs === null) {
            return;
        }
        self::requireSecret($validator, $existing, $needs);
    }

    protected function sourceConfig(ProjectTrigger $trigger, GatewayContext $context): array
    {
        $config = $trigger->config ?? [];
        $stream = $config['stream'] ?? null;

        return array_filter([
            'url' => (string) ($config['url'] ?? ''),
            'subject' => (string) ($config['subject'] ?? ''),
            'stream' => $stream,
            'durable' => $stream ? (($config['durable'] ?? null) ?: self::defaultName($context, $trigger, '-')) : null,
            'deliver' => $stream ? ($config['deliver'] ?? 'new') : null,
            'queue_group' => $stream ? null : (($config['queue_group'] ?? null) ?: self::defaultName($context, $trigger)),
            'ack_wait' => $config['ack_wait'] ?? null,
            'auth' => match ($config['auth']['mode'] ?? 'none') {
                'user' => ['user' => (string) ($config['auth']['user'] ?? '')],
                default => null,
            },
            'tls' => $config['tls'] ?? null,
        ], fn ($v) => $v !== null);
    }

    protected function sourceSecrets(ProjectTrigger $trigger): array
    {
        return match ($trigger->setting('auth.mode', 'none')) {
            'user' => ['auth.password' => $trigger->secret('password')],
            'token' => ['auth.token' => $trigger->secret('token')],
            'nkey' => ['auth.nkey_seed' => $trigger->secret('nkey_seed')],
            'creds' => ['auth.creds' => $trigger->secret('creds')],
            default => [],
        };
    }

    public function fields(): array
    {
        return [
            $this->field('url', required: true),
            $this->field('subject', required: true),
            $this->field('stream'),
            $this->field('durable'),
            $this->field('deliver', 'select', default: 'new', options: ['new', 'all']),
            $this->field('queue_group'),
            $this->field('auth.mode', 'select', default: 'none', options: ['none', 'user', 'token', 'nkey', 'creds']),
            $this->field('auth.user'),
            $this->field('password', secret: true),
            $this->field('token', secret: true),
            $this->field('nkey_seed', secret: true),
            $this->field('creds', 'text', secret: true),
            ...$this->tlsFields(),
        ];
    }
}
