<?php

namespace App\Services\Environments;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The environment an API request names (an `environment` field or query parameter, its name or
 * id): null for none, the primary's name ("production") or id — the project's own workload —
 * else the environment. An unknown one answers 422 with the field's error.
 */
final class EnvironmentResolver
{
    public static function resolve(Project $project, mixed $reference, string $field = 'environment'): ?ProjectEnvironment
    {
        if ($reference === null || $reference === '' || ! is_string($reference)) {
            return null;
        }

        $reference = strtolower(trim($reference));
        if ($reference === ProjectEnvironment::PRIMARY_NAME || ! ProjectEnvironment::tablesReady()) {
            if ($reference === ProjectEnvironment::PRIMARY_NAME) {
                return null;
            }
            self::unknown($field, $reference);
        }

        $environment = ProjectEnvironment::query()
            ->where('project_id', $project->getKey())
            ->where(fn ($q) => $q->where('name', $reference)->orWhere('id', $reference))
            ->first();

        if (! $environment) {
            self::unknown($field, $reference);
        }

        return $environment->is_primary ? null : $environment;
    }

    /** Like resolve(), the primary as its row (created on first use) instead of null. */
    public static function resolveRow(Project $project, mixed $reference, string $field = 'environment'): ProjectEnvironment
    {
        return self::resolve($project, $reference, $field) ?? ProjectEnvironment::primaryFor($project);
    }

    private static function unknown(string $field, string $reference): never
    {
        $message = (string) __('environments.unknown', ['name' => mb_substr($reference, 0, 64)]);

        throw new HttpResponseException(response()->json(['message' => $message, 'code' => 'environment_not_found', 'errors' => [$field => [$message]]], 422));
    }
}
