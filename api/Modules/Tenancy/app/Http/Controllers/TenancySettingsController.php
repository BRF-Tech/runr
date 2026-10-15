<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\SettingsController;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Http\Controllers\Concerns\TenancySettingsQueryTrait;
use Modules\Tenancy\Services\TenantDomainService;

class TenancySettingsController extends SettingsController
{
    use TenancySettingsQueryTrait;

    public function update(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        // Central mode → delegate to base controller (supports registration_enabled)
        if (! $tenant) {
            return parent::update($request);
        }

        $request->validate([
            'base_domain' => 'sometimes|nullable|string|max:253',
            'registration_enabled' => 'prohibited',
        ]);

        if ($request->has('base_domain')) {
            $oldDomain = Setting::get('base_domain');
            $newDomain = $request->base_domain ?: null;

            Setting::set('base_domain', $newDomain ?? '');

            $this->syncTenantDomain($tenant, $oldDomain, $newDomain);
        }

        return response()->json($this->buildSettingsData());
    }

    private function syncTenantDomain($tenant, ?string $old, ?string $new): void
    {
        $service = app(TenantDomainService::class);

        if ($old && $new && $old !== $new) {
            $service->removeDomainFromAllRegions($tenant, $old);
            $service->syncDomainToRegions($tenant, $new);
        } elseif ($old && ! $new) {
            $service->removeDomainFromAllRegions($tenant, $old);
        } elseif (! $old && $new) {
            $service->syncDomainToRegions($tenant, $new);
        }
    }
}
