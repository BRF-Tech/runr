<?php

namespace App\Mcp\Tools\Catalog\Regions;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class RegionsList extends Tool
{
    public function name(): string
    {
        return 'regions_list';
    }

    public function title(): string
    {
        return 'List regions';
    }

    public function description(): string
    {
        return 'Lists the regions (Kubernetes clusters) projects can run in: id, name, whether they are active, Knative Serving, Redis (needed by queue triggers), GPUs, sandboxes and capacity. project_create and model services take a region id or name.';
    }

    public function inputSchema(): array
    {
        return Schema::object();
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
        $result = $context->api()->get('/knative-regions');
        if (! $result->ok()) {
            throw ToolError::fromApi($result);
        }

        $regions = array_map([self::class, 'summary'], array_values(array_filter((array) $result->data(), 'is_array')));

        return ToolResult::ok(['regions' => $regions], count($regions).' region(s).');
    }

    /** The fields of a region an agent needs. */
    public static function summary(array $region): array
    {
        return array_filter([
            'id' => $region['id'] ?? null,
            'name' => $region['name'] ?? null,
            'active' => $region['is_active'] ?? null,
            'status' => $region['region_status'] ?? null,
            'demo' => ! empty($region['is_demo']) ? true : null,
            'cluster_status' => $region['cluster_status'] ?? null,
            'serving' => $region['has_serving'] ?? null,
            'redis' => $region['has_redis'] ?? null,
            'registry' => $region['has_registry'] ?? null,
            'sandboxes' => array_keys(array_filter([
                'gvisor' => $region['has_sandbox_gvisor'] ?? false,
                'kata' => $region['has_sandbox_kata'] ?? false,
            ])) ?: null,
            'gpu' => $region['gpu'] ?? null,
            'cpu_m' => isset($region['total_cpu_m']) ? ['used' => $region['used_cpu_m'] ?? null, 'total' => $region['total_cpu_m']] : null,
            'memory_mb' => isset($region['total_memory_mb']) ? ['used' => $region['used_memory_mb'] ?? null, 'total' => $region['total_memory_mb']] : null,
            'projects' => $region['project_count'] ?? null,
            'function_domain' => $region['knative_settings']['knative_domain'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
