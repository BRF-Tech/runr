<?php

namespace App\Rules;

use App\Services\Helpers\MonitoringEndpoint;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An operator-supplied Prometheus/Loki: an in-cluster Service on the region's cluster,
 * http(s)://<service>.<namespace>.svc[.cluster.local]:<port>[/path], at most 255 characters.
 * RunR reaches it through the region's Kubernetes API service proxy (MonitoringEndpoint), so
 * external hosts, IPs and metadata endpoints are rejected.
 */
class InClusterServiceUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! MonitoringEndpoint::isValidInput($value)) {
            $fail(__('api.region.monitoring_url_invalid'));
        }
    }
}
