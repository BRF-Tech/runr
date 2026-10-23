<?php

namespace App\Triggers\Types;

use App\Models\ProjectTrigger;
use App\Triggers\GatewayContext;

/**
 * A trigger whose events the RunR API stores (trigger_events) and the project's event gateway
 * pulls from the API's inbox (claim → deliver → ack, App\Triggers\Inbox\InboxService): webhooks
 * and platform events. Stored events stay for retention_days and can be replayed.
 */
abstract class InboxTriggerType extends GatewayTriggerType
{
    public function storesEvents(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected static function retentionRules(): array
    {
        return [
            'retention_days' => 'nullable|integer|min:1|max:'.max(1, (int) config('triggers.event_retention_max_days', 30)),
        ];
    }

    public static function retentionDays(ProjectTrigger $trigger): int
    {
        $max = max(1, (int) config('triggers.event_retention_max_days', 30));
        $days = (int) ($trigger->setting('retention_days') ?? config('triggers.event_retention_days', 7));

        return max(1, min($days, $max));
    }

    protected static function keepRetention(array $config, array $normalized): array
    {
        if (isset($config['retention_days']) && $config['retention_days'] !== '') {
            $normalized['retention_days'] = (int) $config['retention_days'];
        }

        return $normalized;
    }

    public function gatewayEntry(ProjectTrigger $trigger, GatewayContext $context): ?array
    {
        return [
            'kind' => 'inbox',
            'source' => [
                'url' => $context->inboxUrl(),
                'trigger' => $trigger->name,
                'batch' => 10,
                'lease_seconds' => self::leaseSeconds($trigger, $context->timeoutSeconds),
                'poll_interval' => max(1, (int) config('triggers.inbox_poll_seconds', 15)).'s',
            ],
        ];
    }

    /**
     * How long the gateway holds a claimed event: every attempt (delivery timeout + slack) and
     * every backoff of the trigger's delivery settings, plus a minute — after it the API offers
     * the event again (a gateway that died mid-delivery). At most 6 hours.
     */
    public static function leaseSeconds(ProjectTrigger $trigger, int $timeoutSeconds = 0): int
    {
        $delivery = self::delivery($trigger);
        $timeout = max(30, $timeoutSeconds) + 5;
        $attempts = $delivery['max_retries'] + 1;

        $backoff = 0;
        $wait = $delivery['retry_backoff_seconds'];
        for ($i = 1; $i <= $delivery['max_retries']; $i++) {
            $backoff += min($wait, $delivery['retry_backoff_max_seconds']);
            $wait *= 2;
        }

        return min(6 * 3600, $attempts * $timeout + $backoff + 60);
    }
}
