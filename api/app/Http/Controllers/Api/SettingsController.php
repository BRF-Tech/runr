<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SettingsQueryTrait;
use App\Models\Setting;
use App\Services\Concerns\BroadcastsModelEvents;
use App\Services\DomainMigrateService;
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

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'base_domain' => 'sometimes|nullable|string|max:253',
            'registration_enabled' => 'sometimes|boolean',
            'gpu_demo_enabled' => 'sometimes|boolean',
            'custom_runtimes_enabled' => 'sometimes|boolean',
        ]);

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
            'total_cpu_m',
            'total_memory_mb',
            'demo_status',
        ]));
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
