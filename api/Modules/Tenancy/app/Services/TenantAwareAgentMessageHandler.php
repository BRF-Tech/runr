<?php

namespace Modules\Tenancy\Services;

use App\Models\Project;
use App\Services\AgentMessageHandler;
use Closure;
use Modules\Tenancy\Models\Tenant;

/**
 * Tenant-aware agent message handler. A project the central schema does not have belongs to a
 * tenant: tenant project slugs end with the tenant's slug ({name}-{tenant slug}, see
 * TenancyServiceOverridesTrait::registerProjectSlugResolver). The project is looked up in that
 * tenant's schema and the message is handled there, so the deployment sessions and cache
 * entries it writes land in the tenant's context — not in the central schema.
 */
class TenantAwareAgentMessageHandler extends AgentMessageHandler
{
    protected function withProject(string $slug, string $regionId, Closure $callback): mixed
    {
        // Central schema first (single-tenant projects, platform context)
        if ($project = $this->findProject($slug, $regionId)) {
            return $callback($project);
        }

        foreach ($this->candidateTenants($slug) as $tenant) {
            [$found, $result] = $tenant->run(function () use ($slug, $regionId, $callback) {
                $project = $this->findProject($slug, $regionId);

                return $project ? [true, $callback($project)] : [false, null];
            });

            if ($found) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Tenants whose slug is a suffix of the project slug after a dash, longest slug first
     * (tenant slugs may contain dashes themselves: "shop-acme-eu" → "acme-eu", then "eu").
     *
     * @return list<Tenant>
     */
    private function candidateTenants(string $slug): array
    {
        $parts = explode('-', $slug);
        $candidates = [];
        for ($i = 1; $i < count($parts); $i++) {
            $candidates[] = implode('-', array_slice($parts, $i));
        }

        if ($candidates === []) {
            return [];
        }

        /** @var list<Tenant> $tenants */
        $tenants = Tenant::query()->whereIn('slug', $candidates)->get()->all();
        usort($tenants, fn (Tenant $a, Tenant $b) => strlen((string) $b->getAttribute('slug')) <=> strlen((string) $a->getAttribute('slug')));

        return $tenants;
    }
}
