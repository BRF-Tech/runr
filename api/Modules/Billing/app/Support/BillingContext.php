<?php

namespace Modules\Billing\Support;

use App\Support\PlatformConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Env;

/**
 * The organization a billing request or job works in (docs/billing.md#where-billing-data-lives):
 *
 *  - install   single-tenant install: everything in the default (central) database
 *  - platform  multi-tenant install without an active tenant: platform administrators, the
 *              platform's own projects and the platform → tenant bills (central schema)
 *  - tenant    an initialized tenant: its projects' bills, prices, markups (tenant schema)
 */
final class BillingContext
{
    public const INSTALL = 'install';

    public const PLATFORM = 'platform';

    public const TENANT = 'tenant';

    public static function kind(): string
    {
        if (self::tenant()) {
            return self::TENANT;
        }

        return self::multiTenant() ? self::PLATFORM : self::INSTALL;
    }

    public static function multiTenant(): bool
    {
        return (bool) config('tenancy.enabled');
    }

    public static function inTenant(): bool
    {
        return self::tenant() !== null;
    }

    /** The initialized tenant, null outside one. */
    public static function tenant(): ?Model
    {
        if (! function_exists('tenancy')) {
            return null;
        }

        $tenancy = tenancy();
        $tenant = $tenancy->initialized ? $tenancy->tenant : null;

        return $tenant instanceof Model ? $tenant : null;
    }

    public static function tenantKey(): ?string
    {
        $tenant = self::tenant();

        return $tenant ? (string) $tenant->getKey() : null;
    }

    /** Connection of platform data ("central" with tenancy on, null = the default connection). */
    public static function central(): ?string
    {
        return PlatformConnection::name();
    }

    /** Memo key of the organization (services cache per organization). */
    public static function key(): string
    {
        return self::kind().':'.(self::tenantKey() ?? '-');
    }

    /** @return array{kind: string, id: string|null, slug: string|null, name: string|null} */
    public static function organization(): array
    {
        $tenant = self::tenant();

        return [
            'kind' => self::kind(),
            'id' => $tenant ? (string) $tenant->getKey() : null,
            'slug' => $tenant ? (string) $tenant->getAttribute('slug') : null,
            'name' => $tenant ? (string) $tenant->getAttribute('name') : null,
        ];
    }

    /**
     * The install currency (ISO 4217, upper case). Read from the environment when the module's
     * configuration is not loaded (the module disabled: its central migrations run anyway).
     */
    public static function currency(): string
    {
        $currency = strtoupper(trim((string) (config('billing.currency') ?? Env::get('BILLING_CURRENCY', 'USD'))));

        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }
}
