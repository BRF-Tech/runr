<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $key
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|Setting newModelQuery()
 * @method static Builder<static>|Setting newQuery()
 * @method static Builder<static>|Setting query()
 * @method static Builder<static>|Setting whereCreatedAt($value)
 * @method static Builder<static>|Setting whereKey($value)
 * @method static Builder<static>|Setting whereUpdatedAt($value)
 * @method static Builder<static>|Setting whereValue($value)
 *
 * @mixin Eloquent
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected static ?string $fallbackConnection = null;

    public static function setFallbackConnection(?string $connection): void
    {
        static::$fallbackConnection = $connection;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::find($key)?->value;

        if ($value !== null) {
            return $value;
        }

        if (static::$fallbackConnection) {
            return static::on(static::$fallbackConnection)
                ->where('key', $key)->value('value') ?? $default;
        }

        return $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
