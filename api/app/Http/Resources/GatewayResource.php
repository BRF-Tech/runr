<?php

namespace App\Http\Resources;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GatewayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $baseDomain = Setting::get('base_domain');
        $regionName = $this->resource->knativeRegion?->name;
        $host = $regionName
            ? "{$this->subdomain}.{$regionName}.{$baseDomain}"
            : "{$this->subdomain}.{$baseDomain}";

        return [
            'id' => $this->id,
            'subdomain' => $this->subdomain,
            'custom_domain' => $this->custom_domain,
            'status' => $this->status,
            'knative_region_id' => $this->knative_region_id,
            'region' => $this->whenLoaded('knativeRegion', fn () => [
                'id' => $this->resource->knativeRegion->id,
                'name' => $this->resource->knativeRegion->name,
            ]),
            'host' => $host,
            'rate_limit' => $this->rate_limit_average ? [
                'average' => (int) $this->rate_limit_average,
                'burst' => (int) ($this->rate_limit_burst ?: $this->rate_limit_average),
            ] : null,
            'routes' => $this->whenLoaded('routes', fn () => $this->resource->routes->map(fn ($r) => [
                'id' => $r->id,
                'path' => $r->path,
                'methods' => $r->methods,
                'project_id' => $r->project_id,
                'project' => $r->relationLoaded('project') ? [
                    'id' => $r->project->id,
                    'name' => $r->project->name,
                    'slug' => $r->project->slug,
                ] : null,
                'url' => "https://{$host}{$r->path}",
            ])
            ),
            'created_at' => $this->created_at,
        ];
    }
}
