<?php

namespace App\Http\Controllers\Api\Observability;

use App\Http\Controllers\Api\Controller;
use App\Jobs\InstallTracingJob;
use App\Models\KnativeRegion;
use App\Models\Observability\RegionTracingConfig;
use App\Rules\InClusterServiceUrl;
use App\Services\Observability\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tracing backend of a region: mode (managed / reuse / external / off), reused Tempo,
 * external OTLP export, Knative sampling, Tempo retention; install / remove.
 *
 * @tags Observability
 */
class RegionTracingController extends Controller
{
    /** GET /api/observability/regions/{knativeRegion}/tracing */
    public function show(KnativeRegion $knativeRegion): JsonResponse
    {
        return response()->json(['data' => RegionTracingConfig::forRegion($knativeRegion)->toApi()]);
    }

    /**
     * PUT /api/observability/regions/{knativeRegion}/tracing — store the configuration;
     * install = true applies it to the cluster right away.
     */
    public function update(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(RegionTracingConfig::MODES)],
            'tempo_url' => ['nullable', 'required_if:mode,reuse', 'string', new InClusterServiceUrl],
            'tempo_otlp_endpoint' => ['nullable', 'required_if:mode,reuse', 'string', 'max:255', 'regex:/^(https?:\/\/)?[a-z0-9]([-a-z0-9]*[a-z0-9])?\.[a-z0-9]([-a-z0-9]*[a-z0-9])?(\.svc(\.cluster\.local)?)?:\d{1,5}(\/[A-Za-z0-9._~\-\/]*)?$/'],
            'tempo_org_id' => ['nullable', 'string', 'max:255'],
            'export_endpoint' => ['nullable', 'required_if:mode,external', 'string', 'url:https', 'max:500'],
            // write-only: null keeps the stored headers, an object replaces them, {} clears them
            'export_headers' => ['nullable', 'array', 'max:10'],
            'export_headers.*' => ['string', 'max:2000'],
            'knative_sample_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'retention_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'install' => ['sometimes', 'boolean'],
        ]);

        foreach (array_keys((array) ($data['export_headers'] ?? [])) as $name) {
            if (! preg_match('/^[A-Za-z0-9-]{1,100}$/', (string) $name)) {
                return response()->json(['message' => __('observability.tracing.header_name_invalid'), 'errors' => ['export_headers' => [__('observability.tracing.header_name_invalid')]]], 422);
            }
        }

        $config = RegionTracingConfig::forRegion($knativeRegion);
        $config->fill([
            'tenant_id' => $knativeRegion->owner_tenant_id ?? ($config->tenant_id ?? TenantScope::current()),
            'mode' => $data['mode'],
            'tempo_url' => $data['mode'] === 'reuse' ? $data['tempo_url'] : ($data['mode'] === 'managed' ? null : $config->tempo_url),
            'tempo_otlp_endpoint' => $data['mode'] === 'reuse' ? $data['tempo_otlp_endpoint'] : null,
            'tempo_org_id' => $data['mode'] === 'reuse' ? ($data['tempo_org_id'] ?? null) : null,
            'export_endpoint' => $data['mode'] === 'external' ? $data['export_endpoint'] : null,
        ]);
        if (array_key_exists('export_headers', $data) && $data['export_headers'] !== null) {
            $config->export_headers = $data['export_headers'];
        }
        if ($data['mode'] !== 'external') {
            $config->export_headers = null;
        }
        if (isset($data['knative_sample_rate'])) {
            $config->knative_sample_rate = (float) $data['knative_sample_rate'];
        }
        if (isset($data['retention_hours'])) {
            $config->retention_hours = (int) $data['retention_hours'];
        }
        $config->save();

        if ($request->boolean('install')) {
            return $this->dispatchInstall($request, $knativeRegion, $config);
        }

        return response()->json(['data' => $config->toApi()]);
    }

    /** POST /api/observability/regions/{knativeRegion}/tracing/install */
    public function install(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        return $this->dispatchInstall($request, $knativeRegion, RegionTracingConfig::forRegion($knativeRegion));
    }

    /** DELETE /api/observability/regions/{knativeRegion}/tracing — remove the collector (and RunR's Tempo). */
    public function destroy(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if (! $knativeRegion->kubeconfig) {
            return response()->json(['message' => __('observability.tracing.no_cluster')], 409);
        }

        InstallTracingJob::dispatch($knativeRegion->getKey(), TenantScope::current(), true);

        return response()->json(['message' => __('observability.tracing.uninstall_started')], 202);
    }

    private function dispatchInstall(Request $request, KnativeRegion $region, RegionTracingConfig $config): JsonResponse
    {
        if (! $region->kubeconfig) {
            return response()->json(['message' => __('observability.tracing.no_cluster')], 409);
        }

        $config->tenant_id ??= $region->owner_tenant_id ?? TenantScope::current();
        $config->fill(['status' => $config->mode === 'off' ? null : RegionTracingConfig::STATUS_INSTALLING, 'status_message' => null])->save();

        InstallTracingJob::dispatch($region->getKey(), TenantScope::current());

        return response()->json(['data' => $config->toApi(), 'message' => __('observability.tracing.install_started')], 202);
    }
}
