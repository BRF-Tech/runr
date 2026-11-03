<?php

namespace App\Models;

use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored bot protection settings (docs/security.md#bot-protection): the platform row (tenant_id
 * null) and, multi-tenant, one row per organization that uses its own provider keys. A null
 * column falls back (organization → platform → install default, App\Services\Captcha\CaptchaSettings).
 *
 * The provider secret is encrypted at rest with the application key and never serialized: the
 * API reports only whether one is set.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string|null $provider
 * @property string|null $site_key
 * @property string|null $secret
 * @property list<string>|null $forms
 * @property string|null $fallback
 * @property int|null $verify_timeout_ms
 */
class CaptchaSetting extends Model
{
    use UsesPlatformConnection;

    protected $table = 'captcha_settings';

    protected $fillable = ['tenant_id', 'provider', 'site_key', 'secret', 'forms', 'fallback', 'verify_timeout_ms'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'forms' => 'array',
            'verify_timeout_ms' => 'integer',
        ];
    }

    public static function platform(): ?self
    {
        try {
            return static::query()->whereNull('tenant_id')->first();
        } catch (\Throwable) {
            return null;   // table not migrated yet
        }
    }

    public static function forTenant(string $tenantId): ?self
    {
        try {
            return static::query()->where('tenant_id', $tenantId)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** The decrypted secret, null when none is stored or it cannot be decrypted (APP_KEY changed). */
    public function secretValue(): ?string
    {
        try {
            // the encrypted cast decrypts on read: a changed APP_KEY throws here
            $value = $this->getAttribute('secret');
        } catch (\Throwable) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** A secret is stored but cannot be decrypted (APP_KEY changed): a configuration error, not "no secret". */
    public function secretUnreadable(): bool
    {
        $raw = $this->getAttributes()['secret'] ?? null;
        if ($raw === null || $raw === '') {
            return false;
        }

        try {
            $this->getAttribute('secret');
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    /** Nothing stored any more: every column falls back. */
    public function isEmpty(): bool
    {
        return $this->provider === null && $this->site_key === null && ($this->getAttributes()['secret'] ?? null) === null
            && $this->forms === null && $this->fallback === null && $this->verify_timeout_ms === null;
    }
}
