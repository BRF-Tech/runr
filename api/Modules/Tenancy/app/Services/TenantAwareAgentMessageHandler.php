<?php

namespace Modules\Tenancy\Services;

use App\Models\Project;
use App\Services\AgentMessageHandler;
use Closure;
use Illuminate\Support\Facades\Cache;
use Modules\Tenancy\Models\Tenant;

/**
 * Tenant-aware agent message handler. A project the central schema does not have belongs to a
 * tenant: tenant project slugs end with the tenant's slug ({name}-{tenant slug}, see
 * TenancyServiceOverridesTrait::registerProjectSlugResolver). The project is looked up in that
 * tenant's schema and the message is handled there, so the deployment sessions and cache
 * entries it writes land in the tenant's context — not in the central schema.
 *
 * The tenant is the one the event names (the pod's runr/tenant label, which preview pods carry),
 * else a tenant whose slug ends the project slug, else — for an event that names its project by
 * id — the tenant whose schema has that project. A preview whose Knative Service name had to
 * shorten a long project slug lost the tenant's slug with it (PreviewManifests::serviceName); the
 * previews deployed before their pods carried runr/tenant are found by the project id that way.
 */
class TenantAwareAgentMessageHandler extends AgentMessageHandler
{
    /** How long the tenant of a project id found by a search is remembered (seconds) */
    private const PROJECT_TENANT_TTL = 3600;

    /** How long a project id no tenant has is remembered (seconds) */
    private const UNKNOWN_PROJECT_TTL = 600;

    protected function withProject(string $slug, string $regionId, Closure $callback, bool $anyRegion = false, ?string $projectId = null, ?string $tenant = null): mixed
    {
        // Central schema first (single-tenant projects, platform context)
        if ($project = $this->findProject($slug, $regionId, $anyRegion, $projectId)) {
            return $callback($project);
        }

        $tried = [];
        foreach ($this->candidateTenants($slug, $tenant) as $candidate) {
            $tried[] = (string) $candidate->getKey();
            [$found, $result] = $this->inTenant($candidate, $slug, $regionId, $callback, $anyRegion, $projectId);
            if ($found) {
                return $result;
            }
        }

        if ($projectId !== null && ($owner = $this->tenantOfProject($projectId, $tried)) !== null) {
            [, $result] = $this->inTenant($owner, $slug, $regionId, $callback, $anyRegion, $projectId);

            return $result;
        }

        return null;
    }

    /** @return array{0: bool, 1: mixed} whether the tenant has the project, the callback's result */
    private function inTenant(Tenant $tenant, string $slug, string $regionId, Closure $callback, bool $anyRegion, ?string $projectId): array
    {
        return $tenant->run(function () use ($slug, $regionId, $callback, $anyRegion, $projectId) {
            $project = $this->findProject($slug, $regionId, $anyRegion, $projectId);

            return $project ? [true, $callback($project)] : [false, null];
        });
    }

    /**
     * The tenant the event names ($hint, its slug), then the tenants whose slug is a suffix of the
     * project slug after a dash, longest slug first (tenant slugs may contain dashes themselves:
     * "shop-acme-eu" → "acme-eu", then "eu").
     *
     * @return list<Tenant>
     */
    private function candidateTenants(string $slug, ?string $hint = null): array
    {
        $parts = explode('-', $slug);
        $candidates = [];
        for ($i = 1; $i < count($parts); $i++) {
            $candidates[] = implode('-', array_slice($parts, $i));
        }

        $tenants = [];
        if ($hint !== null) {
            $named = Tenant::query()->where('slug', $hint)->first();
            if ($named instanceof Tenant) {
                $tenants[] = $named;
            }
        }

        if ($candidates !== []) {
            /** @var list<Tenant> $bySuffix */
            $bySuffix = Tenant::query()->whereIn('slug', $candidates)->get()->all();
            usort($bySuffix, fn (Tenant $a, Tenant $b) => strlen((string) $b->getAttribute('slug')) <=> strlen((string) $a->getAttribute('slug')));
            foreach ($bySuffix as $tenant) {
                if (! in_array((string) $tenant->getKey(), array_map(fn (Tenant $t) => (string) $t->getKey(), $tenants), true)) {
                    $tenants[] = $tenant;
                }
            }
        }

        return $tenants;
    }

    /**
     * The tenant whose schema has the project $projectId, searched among the tenants not tried
     * yet; remembered for an hour (an unknown id for ten minutes), so a pod's events search once.
     *
     * @param  list<string>  $tried  tenant ids already looked in
     */
    private function tenantOfProject(string $projectId, array $tried): ?Tenant
    {
        $key = "agent-project-tenant:{$projectId}";
        $known = Cache::get($key);
        if ($known === '') {
            return null;
        }
        if (is_string($known)) {
            $tenant = Tenant::query()->find($known);
            if ($tenant instanceof Tenant && ! in_array((string) $tenant->getKey(), $tried, true)) {
                return $tenant;
            }
            if ($tenant instanceof Tenant) {
                return null; // looked in already: the project is not (or no longer) in the region
            }
        }

        foreach (Tenant::query()->whereNotIn('id', $tried)->get() as $tenant) {
            /** @var Tenant $tenant */
            try {
                $has = $tenant->run(fn () => Project::query()->whereKey($projectId)->exists());
            } catch (\Throwable) {
                continue; // a tenant whose schema is not there (being created or deleted)
            }
            if ($has) {
                Cache::put($key, (string) $tenant->getKey(), self::PROJECT_TENANT_TTL);

                return $tenant;
            }
        }

        Cache::put($key, '', self::UNKNOWN_PROJECT_TTL);

        return null;
    }
}
