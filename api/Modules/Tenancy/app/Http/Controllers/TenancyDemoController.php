<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\DemoController;
use Illuminate\Http\JsonResponse;
use Modules\Tenancy\Jobs\RemoveTenantDemoJob;
use Modules\Tenancy\Jobs\SeedTenantDemoJob;

class TenancyDemoController extends DemoController
{
    public function store(): JsonResponse
    {
        $tenant = request()->attributes->get('tenant');

        if (! $tenant) {
            return parent::store();
        }

        $tenant->update(['data' => array_merge($tenant->data ?? [], ['demo_status' => 'seeding'])]);
        SeedTenantDemoJob::dispatch($tenant, request()->user()->id);

        return response()->json(['demo_status' => 'seeding']);
    }

    public function destroy(): JsonResponse
    {
        $tenant = request()->attributes->get('tenant');

        if (! $tenant) {
            return parent::destroy();
        }

        $tenant->update(['data' => array_merge($tenant->data ?? [], ['demo_status' => 'removing'])]);
        RemoveTenantDemoJob::dispatch($tenant, auth()->id());

        return response()->json(['demo_status' => 'removing']);
    }
}
