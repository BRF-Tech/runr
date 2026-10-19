<?php

namespace App\Http\Resources;

use App\Mcp\Hosting\HostedServerIndex;
use App\Models\ExecutorImage;
use App\Models\Setting;
use App\Models\User;
use App\Support\Rbac\EnvVisibility;
use App\Support\Rbac\Rbac;
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
            'region' => $this->whenLoaded('knativeRegion', fn () => $this->resource->knativeRegion ? [
                'id' => $this->resource->knativeRegion->id,
                'name' => $this->resource->knativeRegion->name,
            ] : null),
            'config' => $this->withEnvVisibility($request, $this->resolveConfig()),
            'endpoint_type' => $this->endpoint_type ?? 'subdomain',
            'endpoint_url' => $this->resolveEndpointUrl(),
            'gateway_route' => $this->resolveGatewayRoute(),
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
            // level "platform" (a platform administrator — only one can resume it) or "organization"
            'stopped' => $this->resource->isStopped() ? $this->stopState($request) : null,
            'runtime_eol' => $this->resolveRuntimeEol(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * When, why and at which level the project was stopped. A platform stop names the platform,
     * not the platform administrator (only platform administrators see who it was).
     *
     * @return array{at: string|null, by: array{id: string, name: string|null}|null, reason: string|null, level: string}
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
        ];
    }

    /** @return array{id: string, name: string|null}|null who stopped the project */
    private function stoppedBy(): ?array
    {
        $id = $this->resource->stopped_by;
        if ($id === null || $id === '') {
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

        if ($triggerType !== 'http') {
            return null;
        }

        // knative_domain already includes region name (e.g. "my-region.runr.sh")
        $region = $this->resource->knativeRegion;
        $knativeDomain = $region?->effective_settings['knative_domain'] ?? null;

        // Fallback: derive from base_domain
        if (! $knativeDomain) {
            $baseDomain = Setting::get('base_domain');
            if (! $baseDomain || ! $region) {
                return null;
            }
            $knativeDomain = "{$region->name}.{$baseDomain}";
        }

        if ($this->endpoint_type === 'gateway') {
            $route = $this->resource->gatewayRoute;
            if ($route) {
                $subdomain = $route->gateway?->subdomain;

                return "https://{$subdomain}.{$knativeDomain}{$route->path}";
            }
        }

        return "https://{$this->slug}.{$knativeDomain}";
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
