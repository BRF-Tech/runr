<?php

namespace App\Services\Audit;

use App\Casts\Encrypted;
use App\Support\Secrets\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Model changes made while an audited request runs (RecordAuditTrail): for the models of
 * AuditCatalog::changeTracked(), which fields changed — with before/after values for non-secret
 * fields only. Secret fields (encrypted / hashed casts, hidden attributes, secret-looking names)
 * appear by name only; env var maps as added / removed / changed names.
 *
 * Scoped per request (Octane: a fresh instance per request).
 */
class AuditChanges
{
    private const MAX_ENTRIES = 25;

    private const MAX_VALUE_LEN = 300;

    private const IGNORED_FIELDS = ['created_at', 'updated_at', 'remember_token', 'last_used_at'];

    private bool $active = false;

    /** @var list<array> */
    private array $entries = [];

    /** Hook the Eloquent events once per application; collection only happens while active. */
    public static function listen(): void
    {
        if (app()->bound('audit.changes.listening')) {
            return;
        }
        app()->instance('audit.changes.listening', true);

        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: *", function (string $name, array $payload) use ($event) {
                $model = $payload[0] ?? null;
                if ($model instanceof Model && app()->bound(self::class)) {
                    app(self::class)->capture($event, $model);
                }
            });
        }
    }

    public function start(): void
    {
        $this->active = true;
        $this->entries = [];
    }

    /** @return list<array> */
    public function stop(): array
    {
        $this->active = false;
        $entries = $this->entries;
        $this->entries = [];

        return $entries;
    }

    public function capture(string $event, Model $model): void
    {
        if (! $this->active || count($this->entries) >= self::MAX_ENTRIES) {
            return;
        }

        $type = AuditCatalog::changeTrackedType($model);
        if ($type === null) {
            return;
        }

        $entry = [
            'model' => $type,
            'id' => (string) $model->getKey(),
            'event' => $event,
        ];
        if ($name = AuditCatalog::displayName($model)) {
            $entry['name'] = $name;
        }

        if ($event === 'updated') {
            $diff = $this->diff($model);
            if ($diff === null) {
                return;
            }
            $entry += $diff;
        } elseif ($event === 'created') {
            $entry['fields'] = array_values(array_diff(array_keys($model->getAttributes()), self::IGNORED_FIELDS, [$model->getKeyName()]));
        }

        $this->entries[] = $entry;
    }

    /** @return array{fields: list<string>, before?: array, after?: array, env_vars?: array}|null */
    private function diff(Model $model): ?array
    {
        $fields = array_values(array_diff(array_keys($model->getChanges()), self::IGNORED_FIELDS));
        if ($fields === []) {
            return null;
        }

        $out = ['fields' => $fields];

        foreach ($fields as $field) {
            if ($field === 'env_vars' || $field === 'config_snapshot') {
                $env = $this->envDiff($model->getOriginal($field), $model->getAttribute($field), $field === 'config_snapshot');
                if ($env !== null && $field === 'env_vars') {
                    $out['env_vars'] = $env;
                }

                continue;
            }

            if (self::isSecretField($model, $field)) {
                continue;
            }

            $out['before'][$field] = $this->scalar($model->getOriginal($field));
            $out['after'][$field] = $this->scalar($model->getAttribute($field));
        }

        return $out;
    }

    /** Names of added / removed / changed env vars — never values. */
    private function envDiff(mixed $before, mixed $after, bool $snapshot): ?array
    {
        if ($snapshot) {
            $before = is_array($before) ? ($before['env_vars'] ?? null) : null;
            $after = is_array($after) ? ($after['env_vars'] ?? null) : null;
        }
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];

        $added = array_map('strval', array_keys(array_diff_key($after, $before)));
        $removed = array_map('strval', array_keys(array_diff_key($before, $after)));
        $changed = [];
        foreach (array_intersect_key($after, $before) as $key => $value) {
            if ((string) $value !== (string) $before[$key]) {
                $changed[] = (string) $key;
            }
        }

        if (! $added && ! $removed && ! $changed) {
            return null;
        }

        return array_filter(['added' => $added, 'removed' => $removed, 'changed' => $changed]);
    }

    public static function isSecretField(Model $model, string $field): bool
    {
        if (in_array($field, $model->getHidden(), true) || SecretRedactor::isSecretKey($field)) {
            return true;
        }

        $cast = $model->getCasts()[$field] ?? null;
        if (! is_string($cast)) {
            return false;
        }

        return str_starts_with($cast, Encrypted::class)
            || str_starts_with($cast, 'encrypted')
            || $cast === 'hashed'
            || str_contains($cast, 'AsEncrypted');
    }

    private function scalar(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return null;
            }
            if (strlen($json) > self::MAX_VALUE_LEN) {
                return mb_substr($json, 0, self::MAX_VALUE_LEN).'…';
            }

            return SecretRedactor::redact(is_array($value) ? $value : json_decode($json, true));
        }
        if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LEN) {
            return mb_substr($value, 0, self::MAX_VALUE_LEN).'…';
        }

        return $value;
    }
}
