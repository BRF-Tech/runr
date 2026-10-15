<?php

namespace App\Http\Requests\KnativeRegion;

use App\Services\Helpers\MonitoringEndpointAccess;
use App\Services\KnativeRedisService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreKnativeRegionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:63', 'unique:knative_regions,name', 'regex:/^[a-z][a-z0-9\-]*[a-z0-9]$/'],
            'kubeconfig' => 'required|string',
            'nodes' => 'present|array',
            'nodes.*' => 'string',
            'is_active' => 'sometimes|boolean',
            // Registry
            'registry_mode' => 'sometimes|nullable|in:internal,external',
            'registry_address' => 'required_if:registry_mode,external|nullable|string',
            'registry_username' => 'nullable|string',
            'registry_password' => 'nullable|string',
            'registry_storage_type' => 'required_if:registry_mode,internal|nullable|in:pvc,s3',
            'registry_storage_config' => 'required_if:registry_mode,internal|nullable|array',
            'registry_storage_config.storageClass' => 'required_if:registry_storage_type,pvc|nullable|string',
            'registry_storage_config.size' => 'required_if:registry_storage_type,pvc|nullable|string',
            'registry_storage_config.endpoint' => 'required_if:registry_storage_type,s3|nullable|string',
            'registry_storage_config.bucket' => 'required_if:registry_storage_type,s3|nullable|string',
            'registry_storage_config.region' => 'required_if:registry_storage_type,s3|nullable|string',
            'registry_storage_config.access_key' => 'required_if:registry_storage_type,s3|nullable|string',
            'registry_storage_config.secret_key' => 'required_if:registry_storage_type,s3|nullable|string',
            'registry_namespace' => 'sometimes|string|max:63',
            // Redis (default managed; "none" = no Redis)
            'redis_mode' => 'sometimes|nullable|in:'.implode(',', KnativeRedisService::INITIAL_MODES),
            'redis_address' => 'nullable|required_if:redis_mode,in_cluster,external|string|max:255',
            'redis_password' => 'nullable|string|max:255',
            // Observability (default managed; supplying a URL reuses an already-running
            // Prometheus/Loki instead of installing RunR's own — e.g. on a cluster that
            // already has a platform monitoring stack). Prometheus and Loki are independent.
            // Only an in-cluster Service of the region's cluster (read through its API proxy).
            ...MonitoringEndpointAccess::rules(),
        ];
    }

    /**
     * prometheus_url / loki_url / loki_org_id: platform administrators (or the tenant owner for
     * their own region) only — see MonitoringEndpointAccess.
     */
    public function withValidator(Validator $validator): void
    {
        MonitoringEndpointAccess::guard($validator, $this);
    }
}
