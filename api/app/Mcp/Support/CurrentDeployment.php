<?php

namespace App\Mcp\Support;

/**
 * The deployment that serves a project, from its newest-first deployment list
 * (GET /api/projects/{project}/deployments): the newest active one, else the newest live one
 * (pending, running), else the newest. Same rule as the CLI's `runr` PickCurrentDeployment.
 */
final class CurrentDeployment
{
    private const LIVE = ['pending', 'running', 'active'];

    /** @param list<array> $deployments newest first */
    public static function pick(array $deployments): ?array
    {
        foreach ($deployments as $deployment) {
            if (($deployment['status'] ?? null) === 'active') {
                return $deployment;
            }
        }
        foreach ($deployments as $deployment) {
            if (in_array($deployment['status'] ?? null, self::LIVE, true)) {
                return $deployment;
            }
        }

        return $deployments[0] ?? null;
    }
}
