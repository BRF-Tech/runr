<?php

namespace App\Http\Requests\KnativeRegion;

use App\Rules\IpOrCidr;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRegionSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'namespace' => 'sometimes|string|max:63',
            'request_timeout' => 'sometimes|integer|min:1|max:3600',
            // runr-agent: RunR gateway base URL as seen from the region (/ws/agent/{id} appended)
            'agent_ws_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'regex:#^(wss?|https?)://[^\s]+$#i'],
            // cert-manager ClusterIssuer for project custom-domain certificates
            'cert_manager_cluster_issuer' => ['sometimes', 'nullable', 'string', 'max:253', 'regex:/^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/'],
            // Region Traefik: addresses of a load balancer / proxy in front of it that
            // sends PROXY protocol or X-Forwarded-For; empty / null = trust nobody (IngressTrust)
            'ingress_trusted_ips' => ['sometimes', 'nullable', 'array', 'max:32'],
            'ingress_trusted_ips.*' => ['required', 'string', 'max:64', 'distinct', new IpOrCidr],
        ];
    }
}
