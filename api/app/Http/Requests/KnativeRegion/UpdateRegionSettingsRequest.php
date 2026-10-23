<?php

namespace App\Http\Requests\KnativeRegion;

use App\Rules\IpOrCidr;
use App\Services\CertManager\CertManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRegionSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // The project namespace is the region name and never changes (no `namespace` key)
        return [
            // the longest project timeout; Traefik and Knative follow it (RequestTimeout)
            'request_timeout' => 'sometimes|integer|min:1|max:3600',
            // runr-agent: RunR gateway base URL as seen from the region (/ws/agent/{id} appended)
            'agent_ws_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'regex:#^(wss?|https?)://[^\s]+$#i'],
            // cert-manager ClusterIssuer for project custom-domain certificates: an operator's own
            // issuer; empty (or runr-acme / runr-ca) = RunR's default issuer (CertManagerService)
            'cert_manager_cluster_issuer' => ['sometimes', 'nullable', 'string', 'max:253', 'regex:/^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/'],
            // RunR's default issuer: auto (ACME where the region is public and an e-mail is set,
            // else the region CA), acme or ca; and the ACME account e-mail (empty: the Helm value)
            'cert_manager_issuer_mode' => ['sometimes', 'nullable', 'string', Rule::in(CertManager::MODES)],
            'cert_manager_acme_email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:254'],
            // Region Traefik: addresses of a load balancer / proxy in front of it that
            // sends PROXY protocol or X-Forwarded-For; empty / null = trust nobody (IngressTrust)
            'ingress_trusted_ips' => ['sometimes', 'nullable', 'array', 'max:32'],
            'ingress_trusted_ips.*' => ['required', 'string', 'max:64', 'distinct', new IpOrCidr],
            // Internal network: enforce the projects' allow lists (also when unset), or audit —
            // admit and log what they would deny, an operator's explicit choice to look first
            // (InternalNetwork::modeOf)
            'internal_auth' => 'sometimes|string|in:enforce,audit',
        ];
    }
}
