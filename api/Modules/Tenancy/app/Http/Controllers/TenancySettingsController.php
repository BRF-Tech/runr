<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\SettingsController;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Http\Controllers\Concerns\TenancySettingsQueryTrait;
use Modules\Tenancy\Services\TenantDomainService;

class TenancySettingsController extends SettingsController
{
    use TenancySettingsQueryTrait;

    /** A host name with at least two labels (example.com, apps.example.com) */
    private const DOMAIN_PATTERN = '/^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/';

    public function update(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        // Central mode → delegate to base controller (supports registration_enabled)
        if (! $tenant) {
            return parent::update($request);
        }

        $request->validate([
            'base_domain' => ['sometimes', 'nullable', 'string', 'max:253', 'regex:'.self::DOMAIN_PATTERN],
            // Complete a move of the organization domain now, whatever DNS answers
            'complete_domain_migration' => 'sometimes|boolean',
            'registration_enabled' => 'prohibited',
            // The organization's time zone (OrgTimezone); unset, the platform's applies
            'timezone' => self::timezoneRules(),
        ]);

        if ($request->has('timezone')) {
            self::updateTimezone($request->input('timezone'));
        }

        // The organization domain (docs/tenancy.md): its functions answer at
        // <project>.<region>.<domain>; empty = the platform's base domain again
        if ($request->has('base_domain')) {
            // The organization's own value: Setting::get() would fall back to the platform's
            // domain, and the platform's config-domain entries are not the organization's to remove
            $oldDomain = self::ownBaseDomain();
            $newDomain = strtolower(trim((string) $request->base_domain)) ?: null;

            // The platform's domain is the default already; as the organization's own it would
            // take over the platform's <region>.<domain> entries for this organization alone
            $platform = Setting::on(config('tenancy.database.central_connection', 'central'))
                ->whereKey('base_domain')->value('value');
            if ($newDomain !== null && $platform && $newDomain === strtolower((string) $platform)) {
                throw ValidationException::withMessages([
                    'base_domain' => __('validation.different', ['attribute' => 'base domain', 'other' => 'platform base domain']),
                ]);
            }

            if ($newDomain === null) {
                Setting::query()->whereKey('base_domain')->delete();
            } else {
                Setting::set('base_domain', $newDomain);
            }

            // A changed domain moves: the functions answer on the old and the new one until DNS
            // of the new one answers (TenantDomainService)
            app(TenantDomainService::class)->changeBaseDomain($tenant, $oldDomain, $newDomain);
        }

        if ($request->boolean('complete_domain_migration')) {
            app(TenantDomainService::class)->complete($tenant);
        }

        return response()->json($this->buildSettingsData());
    }
}
