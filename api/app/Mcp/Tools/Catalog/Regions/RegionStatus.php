<?php

namespace App\Mcp\Tools\Catalog\Regions;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class RegionStatus extends Tool
{
    use ReadsProjects;

    public function name(): string
    {
        return 'region_status';
    }

    public function title(): string
    {
        return 'Region status';
    }

    public function description(): string
    {
        return 'The status of one region: cluster and Knative state, the components RunR installed (Knative Serving, registry, Redis, Prometheus, Loki, agent, sandboxes) as the cluster reports them, capacity and GPUs. Use it when deployments in a region fail or hang.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'region' => Schema::string('Region id or name.', ['minLength' => 1, 'maxLength' => 64]),
        ], ['region']);
    }

    public function permissions(): array
    {
        return ['regions.view'];
    }

    public function group(): string
    {
        return 'regions';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $regionId = $this->regionId($context, (string) $arguments['region']);

        $region = $context->api()->get(InternalApi::path('knative-regions', $regionId));
        if (! $region->ok()) {
            throw ToolError::fromApi($region, 'Reading the region');
        }
        $data = (array) $region->data();
        $context->setTarget('region', $regionId, isset($data['name']) ? (string) $data['name'] : null);

        $details = RegionsList::summary($data) + array_filter([
            'knative_status' => $data['knative_status'] ?? null,
            'knative_message' => $data['knative_status_message'] ?? null,
            'cluster_message' => $data['cluster_status_message'] ?? null,
            'redis_status' => $data['knative_redis_status'] ?? null,
            'registry_status' => $data['registry_status'] ?? null,
            'migrating' => ! empty($data['is_migrating']) ? true : null,
        ], fn ($v) => $v !== null);

        // Live component state from the cluster: best effort, the region record answers anyway
        $components = $context->api()->get(InternalApi::path('knative-regions', $regionId, 'component-status'));
        if ($components->ok() && is_array($components->json)) {
            $details['components'] = array_diff_key($components->json, ['region' => true]);
        } else {
            $details['components_error'] = $components->message();
        }

        return ToolResult::ok(['region' => $details], sprintf('Region %s: %s.', $data['name'] ?? $regionId, $data['region_status'] ?? ($data['is_active'] ?? false ? 'active' : 'inactive')));
    }
}
