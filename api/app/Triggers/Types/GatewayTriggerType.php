<?php

namespace App\Triggers\Types;

use App\Models\ProjectTrigger;
use App\Triggers\TriggerType;

/**
 * A trigger the project's event gateway runs: its events reach the function as CloudEvents over
 * HTTP with the common delivery settings (config.delivery: concurrency, max_retries,
 * retry_backoff_seconds, retry_backoff_max_seconds — defaults in config/triggers.php).
 */
abstract class GatewayTriggerType extends TriggerType
{
    public function rules(): array
    {
        return $this->configRules() + self::deliveryRules();
    }

    /** @return array<string, mixed> rules of the type's own config keys */
    abstract protected function configRules(): array;

    /** @return array<string, mixed> */
    public static function deliveryRules(): array
    {
        return [
            'delivery' => 'nullable|array',
            'delivery.concurrency' => 'nullable|integer|min:1|max:32',
            'delivery.max_retries' => 'nullable|integer|min:0|max:20',
            'delivery.retry_backoff_seconds' => 'nullable|integer|min:1|max:300',
            'delivery.retry_backoff_max_seconds' => 'nullable|integer|min:1|max:3600',
        ];
    }

    /** The trigger's delivery settings with the defaults (config/triggers.php). */
    public static function delivery(ProjectTrigger $trigger): array
    {
        $defaults = (array) config('triggers.delivery', []);
        $own = array_filter((array) ($trigger->setting('delivery') ?? []), fn ($v) => $v !== null && $v !== '');

        $delivery = [
            'concurrency' => (int) ($own['concurrency'] ?? $defaults['concurrency'] ?? 4),
            'max_retries' => (int) ($own['max_retries'] ?? $defaults['max_retries'] ?? 5),
            'retry_backoff_seconds' => (int) ($own['retry_backoff_seconds'] ?? $defaults['retry_backoff_seconds'] ?? 2),
            'retry_backoff_max_seconds' => (int) ($own['retry_backoff_max_seconds'] ?? $defaults['retry_backoff_max_seconds'] ?? 300),
        ];
        $delivery['retry_backoff_max_seconds'] = max($delivery['retry_backoff_max_seconds'], $delivery['retry_backoff_seconds']);

        return $delivery;
    }

    /** Keeps the delivery settings a trigger set (normalize() of a subclass adds its own keys). */
    protected static function keepDelivery(array $config, array $normalized): array
    {
        $delivery = array_filter((array) ($config['delivery'] ?? []), fn ($v) => $v !== null && $v !== '');
        $delivery = array_map('intval', array_intersect_key($delivery, array_flip(['concurrency', 'max_retries', 'retry_backoff_seconds', 'retry_backoff_max_seconds'])));
        if ($delivery !== []) {
            $normalized['delivery'] = $delivery;
        }

        return $normalized;
    }
}
