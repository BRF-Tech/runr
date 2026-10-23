<?php

namespace App\Triggers\Types\Sources;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Triggers\GatewayContext;
use Illuminate\Validation\Validator;

/**
 * Inbound email (runtime/queue-consumer/sources/email): the gateway reads a mailbox over IMAP
 * every poll_interval and sends each unseen message as dev.runr.email.received (parsed: from, to,
 * subject, text, html, attachments). A message is marked seen, moved or deleted (after) only once
 * delivered or dead-lettered. The password is the trigger's secret `password`.
 */
class EmailTrigger extends BrokerTriggerType
{
    public function key(): string
    {
        return 'email';
    }

    protected function configRules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => 'nullable|integer|min:1|max:65535',
            'tls' => 'nullable|string|in:implicit,starttls,none',
            'username' => 'required|string|max:255',
            'mailbox' => 'nullable|string|max:255',
            'poll_interval' => ['nullable', 'string', KafkaTrigger::DURATION],
            'after' => 'nullable|string|in:mark_seen,move,delete',
            'move_to' => 'nullable|required_if:config.after,move|string|max:255',
            'max_message_kb' => 'nullable|integer|min:16|max:51200',
            'include_raw' => 'nullable|boolean',
        ];
    }

    public function secretRules(): array
    {
        return ['password' => 'nullable|string|max:1024'];
    }

    public function normalize(array $config): array
    {
        $tls = in_array($config['tls'] ?? 'implicit', ['implicit', 'starttls', 'none'], true) ? ($config['tls'] ?? 'implicit') : 'implicit';
        $normalized = self::only($config, ['host', 'username', 'poll_interval', 'move_to', 'max_message_kb' => 'int']);
        $normalized['port'] = (int) ($config['port'] ?? ($tls === 'implicit' ? 993 : 143));
        $normalized['tls'] = $tls;
        $normalized['mailbox'] = (string) (($config['mailbox'] ?? null) ?: 'INBOX');
        $normalized['after'] = in_array($config['after'] ?? 'mark_seen', ['mark_seen', 'move', 'delete'], true) ? ($config['after'] ?? 'mark_seen') : 'mark_seen';
        $normalized['include_raw'] = (bool) ($config['include_raw'] ?? false);

        return self::keepDelivery($config, $normalized);
    }

    public function validate(Validator $validator, Project $project, array $config, ?ProjectTrigger $existing): void
    {
        self::requireSecret($validator, $existing, 'password');
    }

    protected function sourceConfig(ProjectTrigger $trigger, GatewayContext $context): array
    {
        return array_filter($trigger->config ?? [], fn ($v, $k) => $v !== null && $k !== 'delivery', ARRAY_FILTER_USE_BOTH);
    }

    protected function sourceSecrets(ProjectTrigger $trigger): array
    {
        return ['password' => $trigger->secret('password')];
    }

    public function fields(): array
    {
        return [
            $this->field('host', required: true),
            $this->field('port', 'integer', default: 993),
            $this->field('tls', 'select', default: 'implicit', options: ['implicit', 'starttls', 'none']),
            $this->field('username', required: true),
            $this->field('password', required: true, secret: true),
            $this->field('mailbox', default: 'INBOX'),
            $this->field('poll_interval', 'duration', default: '30s'),
            $this->field('after', 'select', default: 'mark_seen', options: ['mark_seen', 'move', 'delete']),
            $this->field('move_to'),
            $this->field('max_message_kb', 'integer', default: 10240),
            $this->field('include_raw', 'boolean', default: false),
        ];
    }
}
