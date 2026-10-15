<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Models\Tenant;

/**
 * GPU quota of a tenant on a region (platform admin): the GPUs its projects and model services
 * may reserve there (count × max(1, min_scale)). null = no GPU limit, 0 = no GPUs.
 */
class TenantGpuQuotaController extends Controller
{
    public function update(Request $request, string $tenant, string $region): JsonResponse
    {
        $data = $request->validate([
            'gpu_quota' => 'present|nullable|integer|min:0|max:100000',
        ]);

        $tenantModel = Tenant::findOrFail($tenant);

        $updated = DB::connection('central')->table('tenant_regions')
            ->where('tenant_id', $tenantModel->id)
            ->where('knative_region_id', $region)
            ->update(['gpu_quota' => $data['gpu_quota'], 'updated_at' => now()]);

        if ($updated === 0 && ! DB::connection('central')->table('tenant_regions')->where('tenant_id', $tenantModel->id)->where('knative_region_id', $region)->exists()) {
            return response()->json(['message' => __('api.tenant.no_region_access')], 404);
        }

        return response()->json([
            'tenant_id' => $tenantModel->id,
            'region_id' => $region,
            'gpu_quota' => $data['gpu_quota'],
        ]);
    }
}
