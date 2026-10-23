<?php

namespace App\Http\Resources;

use App\Models\Deployment;
use App\Models\EnvironmentSecret;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Version;
use App\Services\Environments\DeployTarget;
use App\Services\GatewayService;
use App\Services\Helpers\ResourceReservation;
use App\Services\ProjectDomainService;
use App\Services\Rollouts\DeployStrategy;
use App\Support\Projects\ScaleSchedule;
use App\Support\Rbac\EnvVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * An environment of a project as the API shows it (docs/environments.md#api): its settings, its
 * overrides (env var values only with env.view), its default URL, the region it runs in, its live
 * deployment and the names of its own secret env vars.
 */
final class EnvironmentResource
{
    /**
     * The API document's shape of make() (the controllers' #[Response] attributes): the generator
     * cannot follow the `+` onto ProjectEnvironment::toPublicArray()
     */
    public const OPENAPI_TYPE = 'array{id: string, name: string, is_primary: bool, workload_name: string, knative_region_id: string|null, own_region: bool, position: int, requires_approval: bool, git_branch: string|null, auto_deploy: bool, inherit_secrets: bool, created_at: string|null, updated_at: string|null, region: array{id: string, name: string}|null, overrides: array<string, mixed>, url: string|null, default_url: string|null, deploy_strategy: "direct"|"blue_green"|"canary", scale_schedule: list<array{cron: string, min_scale: int}>|null, scale_schedule_inherited: bool, warm_scale: array{applied: int, applied_at: string|null}|null, gateway_route: array{id: string, gateway_id: string, path: string, gateway_only: bool, url: string|null}|null, deployment: array{id: string, status: string, config_source: string|null, ready_at: string|null, created_at: string|null, version: array{id: string, number: int, label: string}|null}|null, secrets: list<array{name: string, scope: "environment", source: "region", stored_in: "region", version: int, fingerprint: string|null, updated_at: string|null}>}';

    /** @return array<string, mixed> */
    public static function make(Request $request, Project $project, ProjectEnvironment $environment, ?Deployment $live = null): array
    {
        $view = DeployTarget::environment($project, $environment);
        $region = $view->knativeRegion;
        $host = app(ProjectDomainService::class)->defaultHost($view);
        $overrides = $environment->is_primary ? null : EnvVisibility::mask($request, $project->id, $environment->overrideValues());
        /** @var Version|null $version */
        $version = $live?->version;

        // A gateway route of the environment (docs/environments.md#gateway-routes): its URL is the
        // gateway's — production's only while the project uses the gateway endpoint
        $route = $environment->is_primary && $project->endpoint_type !== 'gateway' ? null : $view->gatewayRoute;
        $gatewayHost = $route?->gateway ? app(GatewayService::class)->publicHost($route->gateway) : null;

        return $environment->toPublicArray() + [
            'region' => $region ? ['id' => $region->id, 'name' => $region->name] : null,
            'overrides' => $overrides === null || $overrides === [] ? (object) [] : $overrides,
            'url' => $gatewayHost ? "https://{$gatewayHost}{$route->path}" : ($host ? 'https://'.$host : null),
            'default_url' => $host ? 'https://'.$host : null,
            // How its deploys roll out (docs/deploy-strategies.md#environments): the project's
            // strategy unless it sets its own (GET …/deploy-strategy?environment= has the options)
            'deploy_strategy' => $project->isSupportedType() ? DeployStrategy::forProject($view)->strategy : DeployStrategy::DIRECT,
            // Its timed warmer (docs/scaling.md#timed-warmer): the schedule its workload runs with —
            // its own, or the project's — and what the warmer last applied there
            'scale_schedule' => ScaleSchedule::rows(ResourceReservation::configOf($view)),
            'scale_schedule_inherited' => $environment->is_primary || ! array_key_exists('scale_schedule', $environment->overrideValues()),
            'warm_scale' => self::warmScale($environment->is_primary ? $project : $environment),
            'gateway_route' => $route ? [
                'id' => $route->id,
                'gateway_id' => $route->gateway_id,
                'path' => $route->path,
                'gateway_only' => (bool) $route->gateway_only,
                'url' => $gatewayHost ? "https://{$gatewayHost}{$route->path}" : null,
            ] : null,
            'deployment' => $live ? [
                'id' => $live->id,
                'status' => $live->status,
                'config_source' => $live->getAttribute('config_source'),
                'ready_at' => $live->ready_at?->toIso8601String(),
                'created_at' => $live->created_at?->toIso8601String(),
                'version' => $version !== null ? ['id' => $version->id, 'number' => $version->number, 'label' => $version->label] : null,
            ] : null,
            'secrets' => $environment->is_primary ? [] : EnvironmentSecret::query()->where('environment_id', $environment->id)->orderBy('name')->get()
                ->map(fn (EnvironmentSecret $s) => $s->toPublicArray())->values()->all(),
        ];
    }

    /**
     * What the timed warmer last applied to the workload ({applied, applied_at}, as the project's
     * warm_scale); null while no warmer runs there.
     *
     * @return array{applied: int, applied_at: string|null}|null
     */
    private static function warmScale(Model $workload): ?array
    {
        $applied = $workload->getAttribute('warm_scale_applied');
        if ($applied === null) {
            return null;
        }
        $at = $workload->getAttribute('warm_scale_applied_at');

        return ['applied' => (int) $applied, 'applied_at' => $at instanceof \DateTimeInterface ? $at->format(DATE_ATOM) : null];
    }
}
