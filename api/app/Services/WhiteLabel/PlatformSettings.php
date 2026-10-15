<?php

namespace App\Services\WhiteLabel;

use App\Models\SecureSetting;
use App\Models\Setting;
use App\Support\PlatformConnection;

/**
 * Platform-level key/value settings (the `settings` table of the central database) with typed
 * reads and the install default (config / env) as fallback. Inside a tenant request Setting::get()
 * would read the tenant's own settings first — platform settings are always read centrally here.
 *
 * Secrets go to SecureSetting (encrypted at rest), never to `settings`.
 */
class PlatformSettings
{
    /** @var array<string, string|null> per-instance memo (one request / job) */
    private array $memo = [];

    public function raw(string $key): ?string
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        try {
            $connection = PlatformConnection::name();
            $query = $connection ? Setting::on($connection) : Setting::query();
            $value = $query->where('key', $key)->value('value');
        } catch (\Throwable) {
            // settings table not migrated yet (fresh install, early console commands)
            $value = null;
        }

        return $this->memo[$key] = $value === null ? null : (string) $value;
    }

    public function has(string $key): bool
    {
        $value = $this->raw($key);

        return $value !== null && $value !== '';
    }

    public function string(string $key, ?string $default = ''): ?string
    {
        $value = $this->raw($key);

        return $value === null ? $default : $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->raw($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->raw($key);

        return $value === null || $value === '' || ! is_numeric($value) ? $default : (int) $value;
    }

    /**
     * Comma / newline separated list → trimmed, de-duplicated, non-empty values.
     *
     * @return list<string>
     */
    public function list(string $key, string $default = ''): array
    {
        return self::splitList($this->raw($key) ?? $default);
    }

    public function set(string $key, mixed $value): void
    {
        $stored = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => implode(',', array_map('strval', $value)),
            $value === null => null,
            default => (string) $value,
        };

        $connection = PlatformConnection::name();
        $query = $connection ? Setting::on($connection) : Setting::query();

        if ($stored === null) {
            $query->where('key', $key)->delete();
        } else {
            $query->updateOrCreate(['key' => $key], ['value' => $stored]);
        }

        unset($this->memo[$key]);
    }

    public function forget(string $key): void
    {
        $this->set($key, null);
    }

    public function secret(string $key, ?string $default = null): ?string
    {
        return SecureSetting::getValue($key) ?? ($default === '' ? null : $default);
    }

    /**
     * Write-only secret semantics of the admin endpoints: null → keep, '' → clear, other → replace.
     */
    public function setSecret(string $key, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        SecureSetting::setValue($key, $value);
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /** @return list<string> */
    public static function splitList(?string $value): array
    {
        $parts = preg_split('/[\s,;]+/', (string) $value) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), fn ($v) => $v !== '')));
    }

    public static function configBool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
