<?php

namespace App\Services\Bindings;

use App\Jobs\Bindings\DataServiceJob;
use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Services\Bindings\DataServices\DataService;
use App\Services\Bindings\DataServices\KvService;
use App\Services\Bindings\DataServices\ObjectStoreService;
use App\Services\Bindings\DataServices\PostgresService;
use Illuminate\Support\Facades\Log;

/**
 * Region data services (kv, object, postgres): status, install (queued), uninstall (queued,
 * refused while bindings of any organization use the service).
 */
class DataServiceManager
{
    /** Request options that are credentials (kept on the encrypted row only) */
    public const SECRET_OPTIONS = ['admin_token', 'access_key_id', 'secret_access_key'];

    public function service(string $name): DataService
    {
        return match ($name) {
            'kv' => app(KvService::class),
            'object' => app(ObjectStoreService::class),
            'postgres' => app(PostgresService::class),
            default => throw new BindingException("Unknown data service {$name}."),
        };
    }

    /**
     * Every service of the region (row status, public config, bindings of this organization);
     * $live adds a health check of the installed ones.
     */
    public function status(KnativeRegion $region, bool $live = false): array
    {
        $services = [];
        foreach (BindingRegionService::SERVICES as $name) {
            $row = BindingRegionService::forRegion($region, $name);

            $entry = [
                'service' => $name,
                'installed' => (bool) $row,
                'mode' => $row?->mode,
                'status' => $row?->status ?? BindingRegionService::STATUS_NOT_INSTALLED,
                'status_message' => $row?->status_message,
                'config' => $row ? $this->service($name)->describe($row) : null,
                'bindings_count' => Binding::query()->where('knative_region_id', $region->getKey())->where('type', $name)->count(),
                'updated_at' => $row?->updated_at?->toIso8601String(),
            ];

            if ($live && $row?->isReady()) {
                try {
                    $entry['health'] = $this->service($name)->health($region, $row);
                } catch (\Throwable $e) {
                    $entry['health'] = ['state' => 'error', 'message' => $e->getMessage(), 'details' => []];
                }
            }

            $services[$name] = $entry;
        }

        return [
            'region_id' => $region->getKey(),
            'namespace' => BindingsCluster::dataNamespace(),
            'services' => $services,
            'region_redis' => [
                'mode' => $region->redis_mode,
                'status' => $region->knative_redis_status,
                'has_password' => (bool) $region->redis_password,
            ],
        ];
    }

    public function requestInstall(KnativeRegion $region, string $service, array $options): BindingRegionService
    {
        $row = BindingRegionService::firstOrNewFor($region, $service);
        if (in_array($row->status, [BindingRegionService::STATUS_INSTALLING, BindingRegionService::STATUS_UNINSTALLING], true)) {
            throw new BindingException(__('api.bindings.service_busy', ['service' => $service]));
        }

        $row->status = BindingRegionService::STATUS_INSTALLING;
        $row->status_message = null;
        if (! $row->exists) {
            $row->mode = $options['mode'] ?? 'managed';
        }

        // Credentials of an external store go to the encrypted row, never into the queue payload
        $secrets = array_filter(array_intersect_key($options, array_flip(self::SECRET_OPTIONS)), fn ($v) => $v !== null && $v !== '');
        if ($secrets) {
            $row->secrets = array_merge((array) $row->secrets, $secrets);
        }
        $options = array_diff_key($options, array_flip(self::SECRET_OPTIONS));
        $row->save();

        DataServiceJob::dispatch('install', (string) $region->getKey(), $service, $options, auth()->id() ? (string) auth()->id() : null);

        return $row;
    }

    /** Job body of an install. */
    public function install(KnativeRegion $region, string $service, array $options): void
    {
        $row = BindingRegionService::firstOrNewFor($region, $service);

        try {
            $this->service($service)->install($region, $row, $options);

            $row->status = BindingRegionService::STATUS_READY;
            $row->status_message = null;
            $row->save();

            BindingAudit::region('data_service.installed', $region, $service, ['mode' => $row->mode]);
        } catch (\Throwable $e) {
            $row->status = BindingRegionService::STATUS_ERROR;
            $row->status_message = mb_substr($e->getMessage(), 0, 2000);
            $row->save();

            Log::warning('Bindings: data service install failed', ['region' => $region->name, 'service' => $service, 'error' => $e->getMessage()]);
        }
    }

    public function requestUninstall(KnativeRegion $region, string $service): BindingRegionService
    {
        $row = BindingRegionService::forRegion($region, $service);
        if (! $row) {
            throw new BindingException(__('api.bindings.service_not_installed', ['service' => $service]));
        }

        $inUse = $this->bindingsOn($region, $service);
        if ($inUse > 0) {
            throw new BindingException(__('api.bindings.service_in_use', ['service' => $service, 'count' => $inUse]));
        }

        if ($service === 'object' && BindingRegionService::forRegion($region, 'postgres')?->configValue('backups')) {
            throw new BindingException(__('api.bindings.object_used_by_backups'));
        }

        $row->status = BindingRegionService::STATUS_UNINSTALLING;
        $row->status_message = null;
        $row->save();

        DataServiceJob::dispatch('uninstall', (string) $region->getKey(), $service, [], auth()->id() ? (string) auth()->id() : null);

        return $row;
    }

    /** Job body of an uninstall. */
    public function uninstall(KnativeRegion $region, string $service): void
    {
        $row = BindingRegionService::forRegion($region, $service);
        if (! $row) {
            return;
        }

        try {
            $this->service($service)->uninstall($region, $row);
            $row->delete();

            BindingAudit::region('data_service.uninstalled', $region, $service);
        } catch (\Throwable $e) {
            $row->status = BindingRegionService::STATUS_ERROR;
            $row->status_message = mb_substr($e->getMessage(), 0, 2000);
            $row->save();

            Log::warning('Bindings: data service uninstall failed', ['region' => $region->name, 'service' => $service, 'error' => $e->getMessage()]);
        }
    }

    /** Bindings of every organization on the region's service (purged ones excluded). */
    public function bindingsOn(KnativeRegion $region, string $service): int
    {
        $count = 0;
        $id = $region->getKey();

        BindingTenancy::eachContext(function () use (&$count, $id, $service) {
            $count += Binding::query()->where('knative_region_id', $id)->where('type', $service)->count();
        });

        return $count;
    }
}
