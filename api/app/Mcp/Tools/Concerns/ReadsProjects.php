<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * Lookups shared by project tools: a project's full resource, a region or binding named by id
 * or name — all through the API, so only what the caller may see is found.
 */
trait ReadsProjects
{
    /** GET /projects/{id} — the ProjectResource. */
    protected function project(ToolContext $context, string $projectId): array
    {
        $result = $context->api()->get(InternalApi::path('projects', $projectId));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the project');
        }

        $project = (array) $result->data();
        if (isset($project['id'])) {
            $context->setTarget('project', (string) $project['id'], isset($project['name']) ? (string) $project['name'] : null);
        }

        return $project;
    }

    /** The project config (xaas / faas / caas config resource). */
    protected static function config(array $project): array
    {
        return is_array($project['config'] ?? null) ? $project['config'] : [];
    }

    /**
     * A region id from an id or a name; null $reference: the only region the caller sees.
     *
     * @throws ToolError
     */
    protected function regionId(ToolContext $context, ?string $reference): string
    {
        $reference = $reference !== null ? trim($reference) : null;
        if ($reference !== null && preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $reference)) {
            return strtolower($reference);
        }

        $result = $context->api()->get('/knative-regions');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Listing regions (pass the region id instead)');
        }

        $regions = array_values(array_filter((array) $result->data(), 'is_array'));
        if ($reference === null || $reference === '') {
            $active = array_values(array_filter($regions, fn ($r) => ($r['is_active'] ?? true) !== false));
            if (count($active) === 1) {
                return (string) $active[0]['id'];
            }

            throw new ToolError('Choose a region: '.(implode(', ', array_map(fn ($r) => ($r['name'] ?? '?').' ('.($r['id'] ?? '?').')', $active)) ?: 'none is available').'.');
        }

        foreach ($regions as $region) {
            if (mb_strtolower((string) ($region['name'] ?? '')) === mb_strtolower($reference) || (string) ($region['id'] ?? '') === $reference) {
                return (string) $region['id'];
            }
        }

        throw new ToolError("No region \"{$reference}\". regions_list shows the regions.");
    }

    /**
     * A binding id from an id or a name.
     *
     * @throws ToolError
     */
    protected function bindingId(ToolContext $context, string $reference): string
    {
        $reference = trim($reference);
        if (preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $reference)) {
            return strtolower($reference);
        }

        $result = $context->api()->get('/bindings');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Listing bindings');
        }
        foreach ((array) $result->data() as $binding) {
            if (is_array($binding) && ($binding['name'] ?? null) === $reference) {
                return (string) $binding['id'];
            }
        }

        throw new ToolError("No binding \"{$reference}\". bindings_list shows the bindings.");
    }

    /**
     * A model service id from an id or a name.
     *
     * @throws ToolError
     */
    protected function modelServiceId(ToolContext $context, string $reference): string
    {
        $reference = trim($reference);
        if (preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $reference)) {
            return strtolower($reference);
        }

        $result = $context->api()->get('/model-services');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Listing model services');
        }
        foreach ((array) $result->data() as $service) {
            if (is_array($service) && (($service['name'] ?? null) === $reference || ($service['service_name'] ?? null) === $reference)) {
                return (string) $service['id'];
            }
        }

        throw new ToolError("No model service \"{$reference}\". model_services_list shows them.");
    }

    /** Drops nulls, empty strings and empty lists from a shaped row. */
    protected static function compact(array $row): array
    {
        return array_filter($row, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
