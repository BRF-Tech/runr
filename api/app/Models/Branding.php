<?php

namespace App\Models;

use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored branding overrides: the platform row (tenant_id null) and, in the multi-tenant edition,
 * one row per tenant that customised what its users see. `settings` holds only the fields that
 * were changed — everything else falls back (tenant → platform → install default, BrandingService).
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property array|null $settings
 * @property string|null $logo_light_path
 * @property string|null $logo_dark_path
 * @property string|null $logo_mark_path
 * @property string|null $favicon_path
 * @property string|null $email_logo_path
 */
class Branding extends Model
{
    use UsesPlatformConnection;

    /** Uploadable assets → their column */
    public const ASSETS = [
        'logo_light' => 'logo_light_path',
        'logo_dark' => 'logo_dark_path',
        'logo_mark' => 'logo_mark_path',
        'favicon' => 'favicon_path',
        'email_logo' => 'email_logo_path',
    ];

    protected $fillable = ['tenant_id', 'settings', 'logo_light_path', 'logo_dark_path', 'logo_mark_path', 'favicon_path', 'email_logo_path'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
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

    public static function platformOrNew(): self
    {
        return static::platform() ?? new static(['tenant_id' => null, 'settings' => []]);
    }

    public static function tenantOrNew(string $tenantId): self
    {
        return static::forTenant($tenantId) ?? new static(['tenant_id' => $tenantId, 'settings' => []]);
    }
}
