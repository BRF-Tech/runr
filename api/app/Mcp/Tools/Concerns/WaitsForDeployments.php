<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * Versions and deployments of long-running tools (deploy, rollback, code_write_files with
 * wait_build, mcp_server_create): resolve a version, wait for its build, start a deployment and
 * wait until it is active or failed — with progress notifications along the way.
 */
trait WaitsForDeployments
{
    /** Deployment states a rollout ends in. */
    protected static array $finalDeploymentStates = ['active', 'failed', 'shut_down'];

    /**
     * The version a reference names: a version id, a number (3, "3", "v3"), "latest" (the newest)
     * or null (the newest).
     *
     * @throws ToolError
     */
    protected function resolveVersion(ToolContext $context, string $projectId, string|int|null $reference): array
    {
        $versions = $this->versions($context, $projectId);
        if ($versions === []) {
            throw new ToolError('The project has no version yet: write its code first (code_write_files).');
        }

        if ($reference === null || $reference === '' || $reference === 'latest') {
            return $versions[0];
        }

        $reference = (string) $reference;
        $number = preg_match('/^v?(\d+)$/i', $reference, $m) ? (int) $m[1] : null;
        foreach ($versions as $version) {
            if ((string) ($version['id'] ?? '') === strtolower($reference) || ($number !== null && (int) ($version['number'] ?? -1) === $number)) {
                return $version;
            }
        }

        throw new ToolError("The project has no version \"{$reference}\". versions_list shows its versions.");
    }

    /** @return list<array> newest first */
    protected function versions(ToolContext $context, string $projectId): array
    {
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'versions'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the versions');
        }

        $versions = array_values(array_filter((array) $result->data(), 'is_array'));
        usort($versions, fn ($a, $b) => (int) ($b['number'] ?? 0) <=> (int) ($a['number'] ?? 0));

        return $versions;
    }

    /**
     * Waits until the version's build is ready (or failed / the wait is over).
     *
     * @return array the version as last read
     */
    protected function waitForBuild(ToolContext $context, string $projectId, array $version, int $deadline): array
    {
        $step = 0;
        while (in_array($version['build_status'] ?? 'ready', ['pending', 'building'], true)) {
            $context->progress(++$step, null, sprintf('Building version %s (%s)…', $version['number'] ?? '?', $version['build_status']));
            if (time() >= $deadline || ! $context->pause()) {
                break;
            }
            foreach ($this->versions($context, $projectId) as $fresh) {
                if (($fresh['id'] ?? null) === ($version['id'] ?? null)) {
                    $version = $fresh;
                    break;
                }
            }
        }

        return $version;
    }

    /**
     * Starts a deployment of $versionId.
     *
     * @return array the deployment (DeploymentResource)
     */
    protected function startDeployment(ToolContext $context, string $projectId, string $versionId, array $extra = []): array
    {
        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'deploy'), ['version_id' => $versionId] + $extra);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Deploy refused');
        }

        return (array) $result->data();
    }

    /** Polls a deployment until it reaches a final state or the deadline. */
    protected function waitForDeployment(ToolContext $context, string $projectId, array $deployment, int $deadline): array
    {
        $step = 0;
        while (! in_array($deployment['status'] ?? null, self::$finalDeploymentStates, true)) {
            $message = $deployment['knative_message'] ?? null;
            $context->progress(++$step, null, 'Deployment '.($deployment['status'] ?? 'pending').($message ? ': '.$message : '').'…');
            if (time() >= $deadline || ! $context->pause()) {
                break;
            }

            $fresh = $context->api()->get(InternalApi::path('projects', $projectId, 'deployments', (string) $deployment['id']));
            if (! $fresh->ok()) {
                throw ToolError::fromApi($fresh, 'Reading the deployment');
            }
            $deployment = (array) $fresh->data();
        }

        return $deployment;
    }

    /** The concise form of a deployment. */
    protected static function deploymentSummary(array $deployment): array
    {
        return array_filter([
            'id' => $deployment['id'] ?? null,
            'status' => $deployment['status'] ?? null,
            'version' => $deployment['version']['label'] ?? null,
            'version_id' => $deployment['version']['id'] ?? null,
            'ready' => $deployment['knative_ready'] ?? null,
            'message' => $deployment['knative_message'] ?? null,
            'deployed_by' => $deployment['deployed_by'] ?? null,
            'ready_at' => $deployment['ready_at'] ?? null,
            'created_at' => $deployment['created_at'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** The concise form of a version. */
    protected static function versionSummary(array $version): array
    {
        return array_filter([
            'id' => $version['id'] ?? null,
            'number' => $version['number'] ?? null,
            'label' => $version['label'] ?? null,
            'message' => $version['commit_message'] ?? null,
            'changed_files' => $version['changed_files'] ?? null,
            'build_status' => $version['build_status'] ?? null,
            'build_error' => $version['build_error'] ?? null,
            'created_at' => $version['created_at'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
