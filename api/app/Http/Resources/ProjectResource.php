<?php

namespace App\Http\Resources;

use App\Mcp\Hosting\HostedServerIndex;
use App\Models\ExecutorImage;
use App\Models\Project;
use App\Models\User;
use App\Services\GatewayService;
use App\Services\GlobalProjects\GlobalProjectService;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\ProjectDomainService;
use App\Services\Projects\ProjectStopService;
use App\Services\Rollouts\DeployStrategy;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Support\Rbac\EnvVisibility;
use App\Support\Rbac\Rbac;
use App\Triggers\TriggerPresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'service_type' => $this->service_type,
            'runtime' => $this->runtime,
            'runtime_version' => $this->runtime_version,
            'description' => $this->description,
            'knative_region_id' => $this->knative_region_id,
            // regional | global; for a global project its regions and their copies (docs/global-projects.md)
            'placement' => $this->resource->isGlobal() ? Project::PLACEMENT_GLOBAL : Project::PLACEMENT_REGIONAL,
            /** @var array{mode: "ha"|"proximity", failover: "automatic"|"manual", failover_after_seconds: int, moving_to: string|null, hostname: string|null, primary_region_id: string, regions: list<array{region_id: string, region_name: string|null, role: "primary"|"secondary", position: int, status: "pending"|"building"|"deploying"|"ready"|"failed"|"removing"|"fenced", status_message: string|null, version: int|null, target_version: int|null, healthy: bool|null, health_checked_at: string|null, address: string|null, in_dns: bool, triggers_active: bool, data_status: string|null, data_message: string|null, unhealthy_since: string|null, fenced_at: string|null}>, bindings: list<array{binding_id: string, name: string, type: string, plan: string|null, home_region_id: string|null, home_region_name: string|null, access: "direct"|"link"|"replicas", regions: list<array{region_id: string, region_name: string|null, kind: string, status: string, status_message: string|null, lag_seconds: int|null, checked_at: string|null}>}>, warnings: list<string>}|null */
            'global' => $this->resource->isGlobal() ? app(GlobalProjectService::class)->summary($this->resource) : null,
            'region' => $this->whenLoaded('knativeRegion', fn () => $this->resource->knativeRegion ? [
                'id' => $this->resource->knativeRegion->id,
                'name' => $this->resource->knativeRegion->name,
            ] : null),
            'config' => $this->withEnvVisibility($request, $this->resolveConfig()),
            // The trigger list (docs/triggers.md); config.trigger_type mirrors its primary trigger
            /** @var list<array{id: string|null, name: string, type: string, label: string, enabled: bool, position: int, primary: bool, runs_on: "service"|"gateway"|"cronjob"|"disabled"|"unknown", config: array<string, mixed>, secrets: list<string>, delivery: array{concurrency: int, max_retries: int, retry_backoff_seconds: int, retry_backoff_max_seconds: int}|null, retention_days: int|null, created_at: string|null, updated_at: string|null, url?: string|null, has_signing_secret?: bool, effective_timezone?: string, timezone_source?: "organization"|"trigger"}> */
            'triggers' => $this->when($this->resource->isSupportedType(), fn () => app(TriggerPresenter::class)->list($this->resource)),
            'endpoint_type' => $this->endpoint_type ?? 'subdomain',
            'endpoint_url' => $this->resolveEndpointUrl(),
            // direct | blue_green | canary (GET /projects/{project}/deploy-strategy has the options)
            /** @var "direct"|"blue_green"|"canary" */
            'deploy_strategy' => $this->resource->isSupportedType() ? DeployStrategy::forProject($this->resource)->strategy : DeployStrategy::DIRECT,
            'gateway_route' => $this->resolveGatewayRoute(),
            // Network (docs/internal-network.md): public | allowlist | internal, and the host other
            // projects of the region call it on
            /** @var "public"|"allowlist"|"internal" */
            'access_mode' => $this->resource->accessMode(),
            'internal_url' => $this->resource->slug ? 'http://'.InternalNetwork::hostOf($this->resource) : null,
            // Hosted MCP server behind the RunR gateway (docs/mcp.md): {handle, url, access_mode} | null
            'mcp_server' => HostedServerIndex::summaryFor($this->resource, $request),
            'latest_version' => new VersionResource($this->whenLoaded('latestVersion')),
            'latest_deployment' => $this->whenLoaded('latestDeployment', function () {
                $d = $this->resource->latestDeployment;
                if (! $d) {
                    return null;
                }

                return [
                    'status' => $d->status,
                    'version' => $d->version ? new VersionResource($d->version) : null,
                ];
            }),
            'created_by' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            // The payer of the project's bills (docs/billing.md): the creator unless transferred
            'owner_id' => $this->resource->payerId(),
            'owner' => $this->whenLoaded('owner', fn () => $this->resource->owner ? [
                'id' => $this->resource->owner->id,
                'name' => $this->resource->owner->name,
                'username' => $this->resource->owner->username,
            ] : null),
            // Stopped by an administrator (POST /projects/{project}/stop): shown to every member;
            // level "platform" (a platform administrator — only one can resume it) or "organization";
            // system "cost_limit" when RunR stopped it by itself (ProjectStopService::stopBySystem)
            'stopped' => $this->resource->isStopped() ? $this->stopState($request) : null,
            'runtime_eol' => $this->resolveRuntimeEol(),
            // Runtime patches (docs/runtimes.md#runtime-patches): state up_to_date | patch_available |
            // pinned | overdue, the most urgent pending level, deadline, current / latest patch, pin
            /** @var array{state: "up_to_date"|"patch_available"|"pinned"|"overdue", level: "routine"|"security"|"critical"|"zero-day"|null, deadline_at: string|null, deployed: bool, current: array{id: string|null, tag: string|null, level: string|null, digest: string|null, published_at: string|null}|null, latest: array{id: string, tag: string, level: string, digest: string, published_at: string|null}|null, pending: list<string>, pin: array{id: string|null, tag: string|null, digest: string|null, available: bool, by: string|null}|null}|null null: no patch concerns the project */
            'runtime_patch' => app(RuntimePatches::class)->state($this->resource),
            // Timed warmer (docs/scaling.md#timed-warmer): the min-scale last applied to the live
            // revision's PodAutoscaler and when; null while no warmer runs
            'warm_scale' => $this->resource->getAttribute('warm_scale_applied') !== null ? [
                'applied' => (int) $this->resource->getAttribute('warm_scale_applied'),
                'applied_at' => $this->resource->getAttribute('warm_scale_applied_at')?->toIso8601String(),
            ] : null,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * When, why and at which level the project was stopped. A platform stop names the platform,
     * not the platform administrator (only platform administrators see who it was); a stop RunR
     * made by itself names no one and says what made it (system, e.g. "cost_limit").
     *
     * @return array{at: string|null, by: array{id: string, name: string|null}|null, reason: string|null, level: string, system: string|null}
     */
    private function stopState(Request $request): array
    {
        $level = $this->resource->getAttribute('stop_level') === 'platform' ? 'platform' : 'organization';
        $showActor = $level !== 'platform' || app(Rbac::class)->isPlatformAdmin($request->user());

        return [
            'at' => $this->resource->stopped_at?->toIso8601String(),
            'by' => $showActor ? $this->stoppedBy() : null,
            'reason' => $this->resource->stop_reason,
            'level' => $level,
            'system' => ProjectStopService::systemOf($this->resource),
        ];
    }

    /** @return array{id: string, name: string|null}|null who stopped the project */
    private function stoppedBy(): ?array
    {
        $id = $this->resource->stopped_by;
        if ($id === null || $id === '' || str_starts_with((string) $id, ProjectStopService::SYSTEM_ACTOR)) {
            return null;
        }

        $user = User::query()->find($id, ['id', 'name']);

        return ['id' => (string) $id, 'name' => $user?->name];
    }

    private function resolveRuntimeEol(): ?string
    {
        if (! $this->runtime || ! $this->runtime_version) {
            return null;
        }

        static $eolMap;
        $eolMap ??= ExecutorImage::eolMap();

        return $eolMap[$this->runtime.':'.$this->runtime_version] ?? null;
    }

    /**
     * Environment variables only for users with env.view on the project (docs/permissions.md).
     * The config also names the project's secret variables (secret_env_keys, never values; null
     * without env.view) — clients write them through config.secret_env_vars.
     */
    private function withEnvVisibility(Request $request, mixed $config): mixed
    {
        if (! $config instanceof JsonResource || ! $config->resource instanceof Model) {
            return $config;
        }

        $visible = EnvVisibility::visible($request, $this->resource);
        $data = $config->resolve($request);
        if ($this->resource->isSupportedType()) {
            $data['secret_env_keys'] = $visible ? $this->secretEnvKeys() : null;
        }

        return $visible ? $data : EnvVisibility::mask($request, $this->resource, $data);
    }

    /** @return list<string> */
    private function secretEnvKeys(): array
    {
        $secrets = $this->resource->relationLoaded('secrets')
            ? $this->resource->secrets
            : $this->resource->secrets()->get(['name']);

        return $secrets->pluck('name')->map(fn ($name) => (string) $name)->sort()->values()->all();
    }

    private function resolveConfig(): mixed
    {
        return match ($this->service_type) {
            'xaas' => new XaasConfigResource($this->whenLoaded('xaasConfig')),
            'faas' => new FaasConfigResource($this->whenLoaded('faasConfig')),
            'caas' => new ContainerConfigResource($this->whenLoaded('containerConfig')),
            'aaas' => new AaasConfigResource($this->whenLoaded('aaasConfig')),
            'agentflow' => new AgentFlowConfigResource($this->whenLoaded('agentFlowConfig')),
            'maas' => new MaasConfigResource($this->whenLoaded('maasConfig')),
            default => null,
        };
    }

    private function resolveEndpointUrl(): ?string
    {
        $triggerType = match ($this->service_type) {
            'xaas' => $this->resource->xaasConfig?->trigger_type,
            'faas' => $this->resource->faasConfig?->trigger_type,
            'caas' => $this->resource->containerConfig?->trigger_type,
            'aaas' => $this->resource->aaasConfig?->trigger_type,
            'agentflow' => $this->resource->agentFlowConfig?->trigger_type,
            'maas' => 'http',
            default => null,
        };

        // queue / schedule trigger, or access mode internal: no public URL
        if ($triggerType !== 'http' || $this->resource->accessMode() === Project::ACCESS_INTERNAL) {
            return null;
        }

        // Global project: the global host name, served by every region's copy
        if ($this->resource->isGlobal() && ($host = app(GlobalProjectService::class)->hostname($this->resource))) {
            return "https://{$host}";
        }

        // The region's function domain (knative_domain, which includes the region name, else
        // <region>.<base_domain>) — the one domain source of project and gateway hosts
        $functionDomain = app(ProjectDomainService::class)->functionDomain($this->resource->knativeRegion);
        if (! $functionDomain) {
            return null;
        }

        if ($this->endpoint_type === 'gateway') {
            $route = $this->resource->gatewayRoute;
            if ($route && $route->gateway && ($host = app(GatewayService::class)->publicHost($route->gateway))) {
                return "https://{$host}{$route->path}";
            }
        }

        return "https://{$this->slug}.{$functionDomain}";
    }

    private function resolveGatewayRoute(): ?array
    {
        if ($this->endpoint_type !== 'gateway') {
            return null;
        }

        $route = $this->resource->gatewayRoute;
        if (! $route) {
            return null;
        }

        return [
            'id' => $route->id,
            'path' => $route->path,
            'methods' => $route->methods,
            'gateway_id' => $route->gateway_id,
        ];
    }
}
