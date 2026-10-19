<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;
use App\Support\Rbac\Rbac;
use Illuminate\Http\Request;

/**
 * Who may set a region's region_mode (managed / infinite tenant quotas, a column of the Tenancy
 * module): the platform's own choice for its regions. Multi-tenant installs take it from platform
 * administrators only — anyone else's value is not taken (the console's region wizard sends the
 * default with every new region), so an organization cannot lift the quotas of a region. A
 * single-tenant install has no organizations: every user who manages regions manages the
 * platform.
 */
final class RegionModeAccess
{
    public static function allows(Request $request): bool
    {
        return ! config('tenancy.enabled') || app(Rbac::class)->isPlatformAdmin($request->user());
    }

    /**
     * The region_mode rule of a region create / update / setup request — where the regions table
     * has the column and the user may set it; otherwise no rule, so the value is not validated
     * and never reaches the region.
     *
     * @return array<string, string>
     */
    public static function rules(Request $request): array
    {
        return KnativeRegion::hasRegionMode() && self::allows($request)
            ? ['region_mode' => 'sometimes|in:managed,infinite']
            : [];
    }
}
