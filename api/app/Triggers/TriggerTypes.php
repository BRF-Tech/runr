<?php

namespace App\Triggers;

/**
 * Registry of the trigger types a project can use (singleton, filled by
 * App\Providers\TriggerServiceProvider). Adding a type: one TriggerType subclass registered here
 * and, when the event gateway runs it, one Go source (runtime/queue-consumer/sources).
 */
final class TriggerTypes
{
    /** @var array<string, TriggerType> */
    private array $types = [];

    public function register(TriggerType $type): void
    {
        $this->types[$type->key()] = $type;
    }

    public function find(?string $key): ?TriggerType
    {
        return $key !== null ? ($this->types[$key] ?? null) : null;
    }

    public function get(string $key): TriggerType
    {
        return $this->types[$key] ?? throw new \InvalidArgumentException("Unknown trigger type: {$key}");
    }

    public function has(?string $key): bool
    {
        return $key !== null && isset($this->types[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->types);
    }

    /** @return array<string, TriggerType> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return list<array<string, mixed>> */
    public function describe(): array
    {
        return array_values(array_map(fn (TriggerType $t) => $t->describe(), $this->types));
    }
}
