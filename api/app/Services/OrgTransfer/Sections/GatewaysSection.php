<?php

namespace App\Services\OrgTransfer\Sections;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\GatewayService;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\Package\PackageFormat;

/**
 * organization/gateways.json: the API gateways (one per member and region) with their rate
 * limit and the routes to the exported projects.
 *
 * Import: the importing member's gateway in the mapped region (GatewayService, created when
 * missing), its rate limit, and every route whose project was imported — path, methods, strip
 * prefix, its authentication (a key or JWT route is created as one; the organization's consumers
 * and keys are not part of the package) and "only through the gateway". A gateway's address
 * carries its member's name, so it changes: the plan says so, and a
 * gateway's custom domain is a manual DNS step.
 */
class GatewaysSection implements TransferSection
{
    public function __construct(private GatewayService $gateways) {}

    public function key(): string
    {
        return 'gateways';
    }

    public function export(ExportContext $context): void
    {
        $projectIds = $context->projects()->pluck('id')->all();
        $rows = [];

        foreach (Gateway::query()->with('routes')->orderBy('created_at')->get() as $gateway) {
            $routes = $gateway->routes->filter(fn (GatewayRoute $r) => in_array($r->project_id, $projectIds, true))
                ->map(fn (GatewayRoute $r) => [
                    'id' => (string) $r->id,
                    'project_id' => $r->project_id,
                    // the environment the route leads to, by name (null: the project's own workload)
                    'environment' => $r->forEnvironment() ? $r->environment?->name : null,
                    'path' => $r->path,
                    'methods' => $r->methods,
                    'strip_prefix' => (bool) $r->strip_prefix,
                    'sort_order' => (int) $r->sort_order,
                    // API product (docs/api-product.md#routes): a key or JWT route stays one
                    'auth_mode' => $r->auth_mode ?: GatewayRoute::AUTH_PUBLIC,
                    'jwt_config' => $r->jwt_config,
                    'gateway_only' => (bool) $r->gateway_only,
                ])->values()->all();
            if ($routes === []) {
                continue;
            }
            $rows[] = [
                'id' => (string) $gateway->id,
                'knative_region_id' => $gateway->knative_region_id,
                'subdomain' => $gateway->subdomain,
                'custom_domain' => $gateway->custom_domain,
                'rate_limit_average' => $gateway->rate_limit_average,
                'rate_limit_burst' => $gateway->rate_limit_burst,
                'routes' => $routes,
            ];
        }

        $context->putJson(PackageFormat::GATEWAYS, $rows);
        $context->count('gateways', count($rows));
    }

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        $gateways = (array) $context->json(PackageFormat::GATEWAYS, []);
        if ($gateways === []) {
            return;
        }

        $plan->manual('gateway_address', __('org_transfer.manual.gateway_address', ['subdomain' => (string) ($context->user->username ?? '?')]));
        $domains = array_values(array_filter(array_column($gateways, 'custom_domain')));
        if ($domains) {
            $plan->manual('dns', __('org_transfer.manual.dns'), $domains);
        }
    }

    public function import(ImportContext $context): void
    {
        $user = $context->user;
        if ($user === null) {
            return;
        }

        foreach ((array) $context->json(PackageFormat::GATEWAYS, []) as $gateway) {
            $region = $context->regionFor($gateway['knative_region_id'] ?? null);
            $sourceId = (string) ($gateway['id'] ?? '');
            if ($region === null) {
                if (! $context->settled('gateway', $sourceId)) {
                    $context->record('gateway', $sourceId, $gateway['subdomain'] ?? $sourceId, 'failed', null, __('org_transfer.plan.region_unmapped', ['region' => (string) ($gateway['knative_region_id'] ?? '?')]));
                }

                continue;
            }

            try {
                $target = $this->gateways->findOrCreateForRegion($user, $region);
                if (! $context->settled('gateway', $sourceId)) {
                    if (! empty($gateway['rate_limit_average']) && $target->rate_limit_average === null) {
                        $target = $this->gateways->updateRateLimit($target, ['average' => (int) $gateway['rate_limit_average'], 'burst' => $gateway['rate_limit_burst'] ?? null]);
                    }
                    if (! empty($gateway['custom_domain'])) {
                        $context->manual('dns', __('org_transfer.manual.dns'), [(string) $gateway['custom_domain']]);
                    }
                    $context->record('gateway', $sourceId, $target->subdomain, 'created', (string) $target->id);
                }
            } catch (\Throwable $e) {
                $context->record('gateway', $sourceId, $gateway['subdomain'] ?? $sourceId, 'failed', null, $e->getMessage());

                continue;
            }

            foreach ((array) ($gateway['routes'] ?? []) as $route) {
                $key = (string) ($route['id'] ?? '');
                if ($key === '' || $context->settled('gateway_route', $key)) {
                    continue;
                }
                $projectId = $context->mapped('project', (string) ($route['project_id'] ?? ''));
                $project = $projectId ? Project::query()->find($projectId) : null;
                if (! $project) {
                    $context->record('gateway_route', $key, (string) ($route['path'] ?? ''), 'skipped', null, __('org_transfer.import.project_not_imported'));

                    continue;
                }
                $path = '/'.ltrim((string) $route['path'], '/');
                if (GatewayRoute::query()->where('gateway_id', $target->id)->where('path', $path)->exists()) {
                    // e.g. a copy into the same organization: the path is the source project's
                    $context->record('gateway_route', $key, "{$target->subdomain}{$path}", 'manual', null, __('org_transfer.import.route_taken', ['path' => $path]));
                    $context->manual('gateway_routes', __('org_transfer.manual.gateway_routes'), ["{$project->name}: {$path}"]);

                    continue;
                }
                // A route to an environment leads to the imported project's environment of that
                // name (EnvironmentsSection runs first)
                $environment = null;
                if (! empty($route['environment']) && is_string($route['environment'])) {
                    $environment = ProjectEnvironment::tablesReady()
                        ? ProjectEnvironment::query()->where('project_id', $project->id)->where('name', $route['environment'])->first()
                        : null;
                    if ($environment === null) {
                        $context->record('gateway_route', $key, (string) ($route['path'] ?? ''), 'skipped', null, __('environments.unknown', ['name' => $route['environment']]));

                        continue;
                    }
                }
                try {
                    // the route's authentication comes with it: a key or JWT route is never public
                    // in the importing organization, not even until a later step
                    $created = $this->gateways->addRoute($target, $project, $path, $route['methods'] ?? null, $environment, $this->settings($route));
                    if (! empty($route['strip_prefix'])) {
                        $created = $this->gateways->updateRoute($created, ['strip_prefix' => true]);
                    }
                    if ($created->gateway_only) {
                        $this->gateways->applyGatewayOnly($created, true);
                    }
                    $context->record('gateway_route', $key, "{$target->subdomain}{$created->path}", 'created', (string) $created->id);
                } catch (\Throwable $e) {
                    $context->record('gateway_route', $key, (string) ($route['path'] ?? ''), 'failed', null, $e->getMessage());
                }
            }
        }
    }

    /**
     * The API product settings of an exported route (a package of an earlier release has none:
     * public). An unknown mode, or a JWT route without its JWKS URL, is imported as a key route —
     * closed, never public.
     *
     * @return array{auth_mode: string, jwt_config: array<string, mixed>|null, gateway_only: bool}
     */
    private function settings(array $route): array
    {
        $mode = (string) ($route['auth_mode'] ?? GatewayRoute::AUTH_PUBLIC);
        $jwt = is_array($route['jwt_config'] ?? null) ? $route['jwt_config'] : null;
        if (! in_array($mode, GatewayRoute::AUTH_MODES, true) || ($mode === GatewayRoute::AUTH_JWT && empty($jwt['jwks_url'] ?? null))) {
            $mode = GatewayRoute::AUTH_KEY;
        }

        return ['auth_mode' => $mode, 'jwt_config' => $jwt, 'gateway_only' => (bool) ($route['gateway_only'] ?? false)];
    }
}
