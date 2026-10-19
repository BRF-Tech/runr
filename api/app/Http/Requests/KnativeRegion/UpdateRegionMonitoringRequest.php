<?php

namespace App\Http\Requests\KnativeRegion;

use App\Services\Helpers\MonitoringEndpointAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PUT /knative-regions/{id}/monitoring: an existing in-cluster Prometheus / Loki for the region
 * (a URL), or RunR's own again (null). Who may set an endpoint: MonitoringEndpointAccess.
 */
class UpdateRegionMonitoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return MonitoringEndpointAccess::rules();
    }

    public function withValidator(Validator $validator): void
    {
        MonitoringEndpointAccess::guard($validator, $this);
    }
}
