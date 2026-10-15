<?php

namespace App\Http\Requests\RegistrySetup;

use App\Services\Helpers\MonitoringEndpointAccess;
use App\Services\KnativeRedisService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RegistrySetupRequest extends FormRequest
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
            'registry_mode' => 'required|in:internal,external',
            // Internal
            'storage_type' => 'required_if:registry_mode,internal|nullable|in:pvc,s3',
            'pvc_config' => 'required_if:storage_type,pvc|nullable|array',
            'pvc_config.storageClass' => 'required_if:storage_type,pvc|nullable|string',
            'pvc_config.size' => 'required_if:storage_type,pvc|nullable|string',
            's3_config' => 'required_if:storage_type,s3|nullable|array',
            's3_config.endpoint' => 'required_if:storage_type,s3|nullable|string',
            's3_config.bucket' => 'required_if:storage_type,s3|nullable|string',
            's3_config.region' => 'required_if:storage_type,s3|nullable|string',
            's3_config.access_key' => 'required_if:storage_type,s3|nullable|string',
            's3_config.secret_key' => 'required_if:storage_type,s3|nullable|string',
            // External
            'external_address' => 'required_if:registry_mode,external|nullable|string',
            'external_username' => 'nullable|string',
            'external_password' => 'nullable|string',
            // Redis (default managed; "none" = no Redis)
            'redis_mode' => 'sometimes|nullable|in:'.implode(',', KnativeRedisService::INITIAL_MODES),
            'redis_address' => 'nullable|required_if:redis_mode,in_cluster,external|string|max:255',
            'redis_password' => 'nullable|string|max:255',
            // Optional: reuse a Prometheus/Loki already running on the cluster (in-cluster Service)
            ...MonitoringEndpointAccess::rules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        MonitoringEndpointAccess::guard($validator, $this);
    }
}
