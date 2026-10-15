<?php

namespace App\Workflows\Engine;

use App\Rules\CronSchedule;

/**
 * A workflow definition as the SDK reports it (introspection) or a manifest declares it,
 * validated and normalized (docs/workflows-protocol.md § Introspection):
 *
 *   {
 *     "name": "process-order",
 *     "description": "…",
 *     "triggers": [{"event": "order.created", "if": "event.data.total > 0"},
 *                  {"cron": "0 9 * * *", "timezone": "Europe/Istanbul"},
 *                  {"queue": "orders"}],
 *     "retries": 3,
 *     "timeout": "1d",
 *     "concurrency": 5 | {"limit": 5, "key": "event.data.customer_id"} | [ … up to 2 ],
 *     "idempotency": "event.data.order_id",
 *     "cancel_on": [{"event": "order.cancelled", "match": "data.order_id"}]
 *   }
 *
 * A {"queue": "<stream>"} trigger is the event "queue/<stream>" (the project's queue bridge).
 */
final class Definition
{
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/';

    public const EVENT_PATTERN = '#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,199}$#';

    /** Path segments of the workflows API (/projects/{project}/workflows/app, …/sync). */
    public const RESERVED_NAMES = ['app', 'sync'];

    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        /** @var list<array<string,mixed>> */
        public readonly array $triggers,
        /** @var array<string,mixed> */
        public readonly array $config,
    ) {}

    /** @throws DefinitionException */
    public static function fromArray(mixed $raw): self
    {
        if (! is_array($raw)) {
            throw new DefinitionException('A workflow definition must be an object');
        }

        $name = $raw['name'] ?? $raw['id'] ?? null;
        if (! is_string($name) || ! preg_match(self::NAME_PATTERN, $name)) {
            throw new DefinitionException('Workflow name must match '.self::NAME_PATTERN.' (letters, digits, ".", "_", "-"; at most 100)');
        }
        if (in_array(strtolower($name), self::RESERVED_NAMES, true)) {
            throw new DefinitionException("Workflow name \"{$name}\" is reserved");
        }

        $description = isset($raw['description']) && is_string($raw['description'])
            ? mb_substr($raw['description'], 0, 2000)
            : null;

        $triggers = self::triggers($raw['triggers'] ?? [], $name);

        $config = [
            'retries' => self::retries($raw['retries'] ?? null, $name),
            'timeout' => self::timeout($raw['timeout'] ?? null, $name),
            'concurrency' => self::concurrency($raw['concurrency'] ?? null, $name),
            'idempotency' => self::expression($raw['idempotency'] ?? null, $name, 'idempotency'),
            'cancel_on' => self::cancelOn($raw['cancel_on'] ?? $raw['cancelOn'] ?? [], $name),
        ];

        return new self($name, $description, $triggers, $config);
    }

    /**
     * @return list<self>
     *
     * @throws DefinitionException
     */
    public static function listFromArray(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new DefinitionException('"workflows" must be a list');
        }

        $max = (int) config('workflows.limits.max_workflows_per_project', 200);
        if (count($raw) > $max) {
            throw new DefinitionException("At most {$max} workflows per project");
        }

        $defs = [];
        $names = [];
        foreach ($raw as $item) {
            $def = self::fromArray($item);
            if (isset($names[$def->name])) {
                throw new DefinitionException("Workflow \"{$def->name}\" is declared twice");
            }
            $names[$def->name] = true;
            $defs[] = $def;
        }

        return $defs;
    }

    public function hash(): string
    {
        return hash('sha256', Protocol::encode([$this->name, $this->description, $this->triggers, $this->config]));
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'triggers' => $this->triggers] + $this->config;
    }

    // ── Sections ───────────────────────────────────────────────────────────

    private static function triggers(mixed $raw, string $name): array
    {
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new DefinitionException("{$name}: \"triggers\" must be a list");
        }
        if (count($raw) > 20) {
            throw new DefinitionException("{$name}: at most 20 triggers");
        }

        $out = [];
        foreach ($raw as $i => $t) {
            if (! is_array($t)) {
                throw new DefinitionException("{$name}: triggers[{$i}] must be an object");
            }

            if (isset($t['cron'])) {
                $cron = is_string($t['cron']) ? trim($t['cron']) : '';
                $tz = $t['timezone'] ?? $t['tz'] ?? null;

                // "TZ=Europe/Istanbul 0 9 * * *" (Inngest style) is accepted too
                if (preg_match('/^(?:CRON_)?TZ=(\S+)\s+(.+)$/', $cron, $m)) {
                    $tz = $m[1];
                    $cron = trim($m[2]);
                }

                if (! CronSchedule::isValid($cron)) {
                    throw new DefinitionException("{$name}: triggers[{$i}]: invalid cron expression \"{$cron}\"");
                }
                if ($tz !== null && (! is_string($tz) || ! in_array($tz, \DateTimeZone::listIdentifiers(), true))) {
                    throw new DefinitionException("{$name}: triggers[{$i}]: unknown timezone");
                }

                $out[] = array_filter(['cron' => $cron, 'timezone' => $tz], fn ($v) => $v !== null);

                continue;
            }

            if (isset($t['queue'])) {
                $stream = $t['queue'];
                if (! is_string($stream) || ! preg_match('/^[a-zA-Z0-9._-]{1,255}$/', $stream)) {
                    throw new DefinitionException("{$name}: triggers[{$i}]: invalid queue stream name");
                }
                $t['event'] = 'queue/'.$stream;
            }

            if (isset($t['event'])) {
                $event = $t['event'];
                if (! is_string($event) || ! preg_match(self::EVENT_PATTERN, $event)) {
                    throw new DefinitionException("{$name}: triggers[{$i}]: invalid event name");
                }
                $trigger = ['event' => $event];
                if ($if = self::expression($t['if'] ?? $t['expression'] ?? null, $name, "triggers[{$i}].if")) {
                    $trigger['if'] = $if;
                }
                $out[] = $trigger;

                continue;
            }

            throw new DefinitionException("{$name}: triggers[{$i}] needs \"event\", \"cron\" or \"queue\"");
        }

        return $out;
    }

    private static function retries(mixed $raw, string $name): int
    {
        if ($raw === null) {
            return (int) config('workflows.defaults.retries', 3);
        }

        // {"attempts": n} counts the first attempt too
        if (is_array($raw) && isset($raw['attempts'])) {
            $raw = (int) $raw['attempts'] - 1;
        }

        $max = (int) config('workflows.limits.max_retries', 20);
        if (! is_int($raw) || $raw < 0 || $raw > $max) {
            throw new DefinitionException("{$name}: \"retries\" must be an integer between 0 and {$max}");
        }

        return $raw;
    }

    private static function timeout(mixed $raw, string $name): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (is_int($raw)) {
            $raw = $raw.'s';
        }

        if (! Duration::isValid($raw) || Duration::milliseconds($raw) <= 0) {
            throw new DefinitionException("{$name}: invalid \"timeout\"");
        }

        if (Duration::seconds($raw) > Duration::seconds(config('workflows.limits.max_run_timeout', '365d'))) {
            throw new DefinitionException("{$name}: \"timeout\" exceeds the platform maximum ".config('workflows.limits.max_run_timeout', '365d'));
        }

        return (string) $raw;
    }

    /** @return list<array{limit:int, key:string|null}> */
    private static function concurrency(mixed $raw, string $name): array
    {
        if ($raw === null) {
            return [];
        }

        $items = is_int($raw) ? [['limit' => $raw]] : (is_array($raw) && ! array_is_list($raw) ? [$raw] : $raw);
        if (! is_array($items) || count($items) > 2) {
            throw new DefinitionException("{$name}: \"concurrency\" takes a limit, an object or a list of at most 2");
        }

        $max = (int) config('workflows.limits.max_concurrency', 1000);
        $out = [];
        $keyed = 0;

        foreach ($items as $item) {
            $limit = is_array($item) ? ($item['limit'] ?? null) : $item;
            if (! is_int($limit) || $limit < 1 || $limit > $max) {
                throw new DefinitionException("{$name}: concurrency limit must be an integer between 1 and {$max}");
            }
            $key = is_array($item) ? self::expression($item['key'] ?? null, $name, 'concurrency.key') : null;
            if ($key !== null && ++$keyed > 1) {
                throw new DefinitionException("{$name}: only one keyed concurrency limit");
            }
            $out[] = ['limit' => $limit, 'key' => $key];
        }

        return $out;
    }

    private static function cancelOn(mixed $raw, string $name): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > 10) {
            throw new DefinitionException("{$name}: \"cancel_on\" must be a list of at most 10");
        }

        $out = [];
        foreach ($raw as $i => $c) {
            $event = is_array($c) ? ($c['event'] ?? null) : null;
            if (! is_string($event) || ! preg_match(self::EVENT_PATTERN, $event)) {
                throw new DefinitionException("{$name}: cancel_on[{$i}]: invalid event name");
            }
            try {
                $expr = Expression::combine($c['match'] ?? null, $c['if'] ?? null);
            } catch (ExpressionException $e) {
                throw new DefinitionException("{$name}: cancel_on[{$i}]: {$e->getMessage()}");
            }
            $out[] = array_filter(['event' => $event, 'expression' => $expr], fn ($v) => $v !== null);
        }

        return $out;
    }

    private static function expression(mixed $raw, string $name, string $field): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_string($raw)) {
            throw new DefinitionException("{$name}: \"{$field}\" must be an expression string");
        }

        try {
            Expression::validate($raw);
        } catch (ExpressionException $e) {
            throw new DefinitionException("{$name}: \"{$field}\": {$e->getMessage()}");
        }

        return trim($raw);
    }
}
