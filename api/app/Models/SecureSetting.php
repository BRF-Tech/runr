<?php

namespace App\Models;

use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform secrets set from the admin UI (payment gateway keys, CAPTCHA secret): encrypted at rest
 * with the application key (`encrypted` cast). Never serialized into API responses — the admin
 * endpoints only report whether a value is set.
 *
 * @property string $key
 * @property string|null $value
 */
class SecureSetting extends Model
{
    use UsesPlatformConnection;

    protected $table = 'secure_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    public static function getValue(string $key): ?string
    {
        try {
            $value = static::query()->find($key)?->value;
        } catch (\Throwable) {
            // table not migrated yet / undecryptable after an APP_KEY change
            return null;
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    public static function setValue(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            static::query()->whereKey($key)->delete();

            return;
        }

        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
