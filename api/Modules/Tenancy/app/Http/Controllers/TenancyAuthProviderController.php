<?php

namespace Modules\Tenancy\Http\Controllers;

use Modules\AuthProvider\Controllers\AuthProviderController;

/**
 * Tenant-aware auth provider controller.
 * Schema-per-tenant (search_path) provides cross-tenant isolation.
 * No logic changes needed — the parent controller queries AuthProvider
 * which resolves to the tenant schema automatically.
 */
class TenancyAuthProviderController extends AuthProviderController
{
    //
}
