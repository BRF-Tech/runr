<?php

namespace App\Triggers\Types\Sources;

use App\Models\ProjectTrigger;
use App\Triggers\GatewayContext;
use App\Triggers\Types\GatewayTriggerType;
use Illuminate\Validation\Validator;

/**
 * A trigger the event gateway reads from a system outside RunR — a broker, a database, an object
 * store, a mailbox (docs/triggers.md#broker-and-external-sources). The type validates and
 * normalizes its config, keeps credentials in the trigger's write-only secrets and renders both
 * into the gateway source's settings (gateway.json, only ever in the Secret <slug>-qc-config);
 * runtime/queue-consumer/sources/<kind> does the reading.
 */
abstract class BrokerTriggerType extends GatewayTriggerType
{
    /** The Go source kind (runtime/queue-consumer/sources/<kind>). */
    protected function kind(): string
    {
        return $this->key();
    }

    /**
     * The source settings without credentials.
     *
     * @return array<string, mixed>
     */
    abstract protected function sourceConfig(ProjectTrigger $trigger, GatewayContext $context): array;

    /**
     * The credentials merged into the source settings (dotted keys allowed), from the trigger's
     * secrets.
     *
     * @return array<string, string|null>
     */
    protected function sourceSecrets(ProjectTrigger $trigger): array
    {
        return [];
    }

    public function gatewayEntry(ProjectTrigger $trigger, GatewayContext $context): ?array
    {
        $source = $this->sourceConfig($trigger, $context);
        foreach ($this->sourceSecrets($trigger) as $key => $value) {
            if ($value !== null && $value !== '') {
                data_set($source, $key, $value);
            }
        }

        return ['kind' => $this->kind(), 'source' => $source];
    }

    /** A default name unique per project and trigger: runr.<project slug>.<trigger name>. */
    protected static function defaultName(GatewayContext $context, ProjectTrigger $trigger, string $separator = '.'): string
    {
        return implode($separator, ['runr', $context->project->slug, $trigger->name]);
    }

    /** @return array<string, mixed> TLS settings of a client (tls.enabled, tls.ca_pem, tls.insecure_skip_verify) */
    protected static function tlsRules(): array
    {
        return [
            'tls' => 'nullable|array',
            'tls.enabled' => 'nullable|boolean',
            'tls.ca_pem' => 'nullable|string|max:16384',
            'tls.insecure_skip_verify' => 'nullable|boolean',
        ];
    }

    protected static function tlsConfig(array $config): ?array
    {
        $tls = (array) ($config['tls'] ?? []);
        if (! ($tls['enabled'] ?? false) && empty($tls['ca_pem'])) {
            return null;
        }

        return array_filter([
            'enabled' => true,
            'ca_pem' => ($tls['ca_pem'] ?? null) ?: null,
            'insecure_skip_verify' => (bool) ($tls['insecure_skip_verify'] ?? false),
        ], fn ($v) => $v !== null);
    }

    /** @return list<array<string, mixed>> */
    protected function tlsFields(): array
    {
        return [
            $this->field('tls.enabled', 'boolean', default: false),
            $this->field('tls.ca_pem', 'text'),
            $this->field('tls.insecure_skip_verify', 'boolean', default: false),
        ];
    }

    /** A write-only secret that must be given now or be stored already. */
    protected static function requireSecret(Validator $validator, ?ProjectTrigger $existing, string $key): void
    {
        $given = $validator->getData()['secrets'][$key] ?? null;
        if (is_string($given) && $given !== '') {
            return;
        }
        if ($given === null && $existing?->secret($key) !== null) {
            return;
        }
        $validator->errors()->add("secrets.{$key}", __('triggers.errors.secret_required', ['secret' => $key]));
    }

    /** Copy the keys that are set (not null / "") from $config. */
    protected static function only(array $config, array $keys): array
    {
        $out = [];
        foreach ($keys as $key => $cast) {
            if (is_int($key)) {
                [$key, $cast] = [$cast, 'string'];
            }
            $value = data_get($config, $key);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            data_set($out, $key, match ($cast) {
                'int' => (int) $value,
                'bool' => (bool) $value,
                'list' => array_values(array_filter(array_map('strval', (array) $value), fn ($v) => $v !== '')),
                'raw' => $value,
                default => (string) $value,
            });
        }

        return $out;
    }
}
