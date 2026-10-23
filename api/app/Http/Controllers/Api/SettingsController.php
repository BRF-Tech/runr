<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SettingsQueryTrait;
use App\Jobs\ApplyInternalNetworkJob;
use App\Jobs\ApplyScheduleTimezonesJob;
use App\Models\Setting;
use App\Services\Concerns\BroadcastsModelEvents;
use App\Services\DomainMigrateService;
use App\Services\InternalNetwork\InternalNetwork;
use App\Support\OrgTimezone;
use App\Support\Rbac\Rbac;
use App\Support\TenantAccess;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    use BroadcastsModelEvents, SettingsQueryTrait;

    public function index(): JsonResponse
    {
        return response()->json($this->buildSettingsData());
    }

    #[BodyParameter('timezone', 'The organization\'s time zone (IANA); null or "" goes back to the default (the platform\'s zone in an organization, else Europe/Istanbul)', type: 'string|null')]
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'base_domain' => 'sometimes|nullable|string|max:253',
            'registration_enabled' => 'sometimes|boolean',
            'gpu_demo_enabled' => 'sometimes|boolean',
            'custom_runtimes_enabled' => 'sometimes|boolean',
            'internal_cross_tenant_grants' => 'sometimes|boolean',
            'timezone' => self::timezoneRules(),
        ]);

        if ($request->has('internal_cross_tenant_grants')) {
            // May projects allow callers of other organizations on the internal network: the
            // platform's call (docs/internal-network.md), outside any organization
            abort_unless(app(Rbac::class)->isPlatformAdmin($request->user()) && TenantAccess::current() === null, 403);
            Setting::set(InternalNetwork::CROSS_TENANT_SETTING, $request->boolean('internal_cross_tenant_grants') ? 'true' : 'false');
            ApplyInternalNetworkJob::dispatchForAllRegions();
        }

        if ($request->has('timezone')) {
            self::updateTimezone($request->input('timezone'));
        }

        if ($request->has('custom_runtimes_enabled')) {
            // Whether organizations may register their own runtimes (runtimes.use_custom)
            abort_unless(Gate::allows('runtimes.manage'), 403);
            Setting::set('custom_runtimes_enabled', $request->boolean('custom_runtimes_enabled') ? 'true' : 'false');
        }

        if ($request->has('registration_enabled')) {
            Setting::set('registration_enabled', $request->boolean('registration_enabled') ? 'true' : 'false');
        }

        if ($request->has('gpu_demo_enabled')) {
            Setting::set('gpu_demo_enabled', $request->boolean('gpu_demo_enabled') ? 'true' : 'false');
        }

        if ($request->has('base_domain')) {
            $oldDomain = Setting::get('base_domain') ?: null;
            $newDomain = $request->base_domain ?: null;

            Setting::set('base_domain', $newDomain ?? '');

            $this->handleDomainChange($oldDomain, $newDomain);
        }

        $settingsData = $this->buildSettingsData();
        $this->broadcastModel('settings.updated', ['settings' => self::broadcastSettings($settingsData)]);

        return response()->json($settingsData);
    }

    /**
     * The `settings` object of the settings.updated broadcast: the console merges it into its
     * settings store (settingsStore.update), which resets base_domain and the capacity totals
     * when they are missing. Regions and executor images stay out — the store keeps its own
     * list and reloads them through GET /settings.
     *
     * @param  array<string, mixed>  $settingsData  buildSettingsData()
     * @return array<string, mixed>
     */
    public static function broadcastSettings(array $settingsData): array
    {
        return array_intersect_key($settingsData, array_flip([
            'base_domain',
            'registration_enabled',
            'gpu_demo_enabled',
            'custom_runtimes_enabled',
            'internal_cross_tenant_grants',
            'timezone',
            'total_cpu_m',
            'total_memory_mb',
            'demo_status',
        ]));
    }

    /**
     * The organization's time zone (OrgTimezone): an IANA zone; null or "" goes back to the
     * default (the platform's zone in a tenant, else Europe/Istanbul).
     *
     * @return array<int, mixed>
     */
    protected static function timezoneRules(): array
    {
        return ['sometimes', 'nullable', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail) {
            if ($value !== null && $value !== '' && ! OrgTimezone::isValid($value)) {
                $fail(__('validation.timezone', ['attribute' => $attribute]));
            }
        }];
    }

    /**
     * Store the organization's time zone in its own settings (the timed warmer reads it on its
     * next run); deployed schedule triggers that follow it get the new zone at once
     * (ApplyScheduleTimezonesJob).
     */
    protected static function updateTimezone(mixed $zone): void
    {
        $zone = is_string($zone) ? trim($zone) : '';
        $before = OrgTimezone::get();

        if ($zone === '') {
            Setting::query()->whereKey(OrgTimezone::SETTING)->delete();
        } else {
            Setting::set(OrgTimezone::SETTING, $zone);
        }

        if (OrgTimezone::get() !== $before) {
            ApplyScheduleTimezonesJob::dispatchForCurrent();
        }
    }

    protected function handleDomainChange(?string $old, ?string $new): void
    {
        $service = app(DomainMigrateService::class);

        if ($old && $new && $old !== $new) {
            $service->startMigration($old, $new);
        } elseif ($old && ! $new) {
            $service->removeDomainFromAllRegions($old);
        } elseif (! $old && $new) {
            $service->addDomainToAllRegions($new);
        }
    }
}
