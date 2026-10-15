<?php

namespace App\Http\Controllers\Api;

use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\DataServiceManager;
use App\Services\Bindings\DataServices\KvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Region data services bindings run on: KV (managed KV Redis or the region's Redis), object
 * storage (Garage, or an external S3 endpoint) and Postgres (CloudNativePG + shared cluster).
 * Changes need regions.data_services.manage (+ region.mutable on the route).
 *
 * @tags Bindings
 */
class DataServiceController extends Controller
{
    private const SIZE = 'regex:/^\d{1,5}(Mi|Gi|Ti)$/';

    public function __construct(private DataServiceManager $manager) {}

    /** Status of the region's data services (?live=1 adds a health check). */
    public function index(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if (! $knativeRegion->kubeconfig) {
            return response()->json(['data' => null, 'abilities' => ['manage' => false]]);
        }

        return response()->json([
            'data' => $this->manager->status($knativeRegion, $request->boolean('live')),
            'abilities' => ['manage' => Gate::allows('regions.data_services.manage', $knativeRegion)],
        ]);
    }

    /** Install or reconfigure a service (queued). */
    public function store(Request $request, KnativeRegion $knativeRegion, string $service): JsonResponse
    {
        Gate::authorize('regions.data_services.manage', $knativeRegion);

        if (! in_array($service, BindingRegionService::SERVICES, true)) {
            abort(404);
        }
        if ($knativeRegion->is_demo || ! $knativeRegion->kubeconfig) {
            return $this->invalid('region', __('api.bindings.region_unavailable', ['region' => $knativeRegion->name]));
        }

        $existing = BindingRegionService::forRegion($knativeRegion, $service);
        $options = $request->validate($this->rules($service, $request, $existing));

        try {
            $row = $this->manager->requestInstall($knativeRegion, $service, $options);
        } catch (BindingException $e) {
            return $this->invalid('service', $e->getMessage());
        }

        return response()->json([
            'message' => __('api.bindings.service_install_queued', ['service' => $service]),
            'data' => ['service' => $service, 'status' => $row->status, 'mode' => $row->mode],
        ], 202);
    }

    /** Uninstall a service (refused while bindings use it). */
    public function destroy(KnativeRegion $knativeRegion, string $service): JsonResponse
    {
        Gate::authorize('regions.data_services.manage', $knativeRegion);

        if (! in_array($service, BindingRegionService::SERVICES, true)) {
            abort(404);
        }

        try {
            $row = $this->manager->requestUninstall($knativeRegion, $service);
        } catch (BindingException $e) {
            return $this->invalid('service', $e->getMessage());
        }

        return response()->json([
            'message' => __('api.bindings.service_uninstall_queued', ['service' => $service]),
            'data' => ['service' => $service, 'status' => $row->status],
        ], 202);
    }

    private function rules(string $service, Request $request, ?BindingRegionService $existing): array
    {
        $external = $request->input('mode') === 'external';
        $garage = $request->input('provider') === 'garage';
        $hasToken = (bool) $existing?->secret('admin_token');
        $hasKeys = (bool) $existing?->secret('access_key_id');

        return match ($service) {
            'kv' => [
                'mode' => ['nullable', Rule::in(KvService::MODES)],
                'maxmemory_mb' => ['nullable', 'integer', 'min:64', 'max:65536'],
                'storage' => ['nullable', 'string', self::SIZE],
            ],
            'object' => [
                'mode' => ['nullable', Rule::in(['managed', 'external'])],
                'storage' => ['nullable', 'string', self::SIZE],
                'provider' => [$external ? 'required' : 'nullable', Rule::in(['garage', 's3'])],
                'endpoint' => [$external ? 'required' : 'nullable', 'url:http,https', 'max:255'],
                'region' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
                'path_style' => ['nullable', 'boolean'],
                'vendor' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9]+$/'],
                'bucket_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
                'admin_endpoint' => [$external && $garage ? 'required' : 'nullable', 'url:http,https', 'max:255'],
                'admin_token' => [$external && $garage && ! $hasToken ? 'required' : 'nullable', 'string', 'max:512'],
                'access_key_id' => [$external && ! $garage && ! $hasKeys ? 'required' : 'nullable', 'string', 'max:256'],
                'secret_access_key' => [$external && ! $garage && ! $hasKeys ? 'required' : 'nullable', 'string', 'max:512'],
            ],
            'postgres' => [
                'instances' => ['nullable', 'integer', 'min:1', 'max:5'],
                'storage' => ['nullable', 'string', self::SIZE],
                'backups' => ['nullable', 'boolean'],
            ],
        };
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
