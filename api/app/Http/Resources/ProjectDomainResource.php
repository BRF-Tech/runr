<?php

namespace App\Http\Resources;

use App\Models\ProjectDomain;
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
            ProjectDomain::TLS_CERT_MANAGER => __('api.domain.tls_note_cert_manager', [
                'issuer' => $service->clusterIssuer($domain->project?->knativeRegion) ?? '-',
            ]),
            default => __('api.domain.tls_note_none'),
        };
    }
}
