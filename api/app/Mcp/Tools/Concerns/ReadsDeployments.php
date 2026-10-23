<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Support\CurrentDeployment;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * A project's deployments: the list (newest first), one by id, or the current one
 * (CurrentDeployment::pick — newest active, else newest live, else newest).
 */
trait ReadsDeployments
{
    /**
     * @param  string|null  $environment  one of the project's environments (a name or id; null: its own workload)
     * @return list<array> newest first
     */
    protected function deployments(ToolContext $context, string $projectId, ?string $environment = null): array
    {
        $query = $environment !== null && trim($environment) !== '' ? ['environment' => trim($environment)] : [];
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'deployments'), $query);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the deployments');
        }

        return array_values(array_filter((array) $result->data(), 'is_array'));
    }

    /**
     * The deployment $reference names, or the current one.
     *
     * @throws ToolError
     */
    protected function deployment(ToolContext $context, string $projectId, ?string $reference = null, ?string $environment = null): array
    {
        if ($reference !== null && trim($reference) !== '' && $reference !== 'current') {
            $result = $context->api()->get(InternalApi::path('projects', $projectId, 'deployments', strtolower(trim($reference))));
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'Reading the deployment');
            }

            return (array) $result->data();
        }

        $current = CurrentDeployment::pick($this->deployments($context, $projectId, $environment));
        if ($current === null) {
            throw new ToolError('The project has not been deployed yet. Deploy it with deploy.');
        }

        return $current;
    }
}
