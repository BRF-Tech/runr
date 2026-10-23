<?php

namespace App\Http\Resources;

use App\Models\ProjectDomain;
use App\Services\CertManager\CertManager;
use App\Services\GlobalProjects\GlobalDomains;
use App\Services\ProjectDomainService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectDomain
 */
class ProjectDomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProjectDomain $domain */
        $domain = $this->resource;
        $service = app(ProjectDomainService::class);
        $project = $domain->project;

        return [
            'id' => $domain->id,
            'project_id' => $domain->project_id,
            'domain' => $domain->domain,
            'status' => $domain->status,
            'status_message' => $domain->status_message,
            'tls_mode' => $domain->tls_mode,
            'tls_expires_at' => $domain->tls_expires_at?->toISOString(),
            'https_certificate' => match ($domain->tls_mode) {
                ProjectDomain::TLS_CUSTOM => 'custom',
                ProjectDomain::TLS_CERT_MANAGER => 'cert-manager',
                default => 'region-default',
            },
            'tls_note' => $this->tlsNote($domain, $service),
            'url' => ($domain->tls_mode === ProjectDomain::TLS_NONE ? 'http://' : 'https://').$domain->domain,
            'verification' => [
                'type' => 'TXT',
                'name' => $domain->challengeName(),
                'value' => $domain->challengeValue(),
            ],
            'dns_targets' => $project
                ? array_map(fn ($t) => ['type' => $t['type'], 'name' => $domain->domain, 'value' => $t['value']], $service->dnsTargets($project))
                : [],
            // a global project (docs/global-projects.md, "Custom domains"): the CNAME every region's
            // certificate follows, and the domain's state in each region
            'acme_challenge' => ProjectDomainService::globalWorkload($project) && $domain->tls_mode === ProjectDomain::TLS_CERT_MANAGER
                && ($target = app(GlobalDomains::class)->challengeTarget($domain->domain))
                ? ['type' => 'CNAME', 'name' => '_acme-challenge.'.$domain->domain, 'value' => $target]
                : null,
            'regions' => ProjectDomainService::globalWorkload($project) ? (object) ((array) ($domain->region_states ?? [])) : null,
            'created_at' => $domain->created_at?->toISOString(),
            'updated_at' => $domain->updated_at?->toISOString(),
        ];
    }

    private function tlsNote(ProjectDomain $domain, ProjectDomainService $service): string
    {
        return match ($domain->tls_mode) {
            ProjectDomain::TLS_CUSTOM => __('api.domain.tls_note_custom', [
                'date' => $domain->tls_expires_at?->toDateString() ?? '-',
            ]),
            ProjectDomain::TLS_CERT_MANAGER => match (CertManager::issuerKind($issuer = $service->clusterIssuer($domain->project?->knativeRegion))) {
                // the region CA: browsers trust it only once it is installed
                'ca' => __('api.domain.tls_note_cert_manager_ca', ['issuer' => $issuer]),
                'acme' => __('api.domain.tls_note_cert_manager_acme', ['issuer' => $issuer]),
                default => __('api.domain.tls_note_cert_manager', ['issuer' => $issuer ?? '-']),
            },
            default => __('api.domain.tls_note_none'),
        };
    }
}
