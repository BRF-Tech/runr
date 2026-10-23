<?php

namespace App\Support\Secrets;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Rules\EnvVars;
use App\Services\Secrets\ProjectSecrets;
use App\Services\Secrets\SecretBackendException;

/**
 * Secret environment variables given as a name → value map: `config.secret_env_vars` of
 * POST /projects and PUT /projects/{project} (the form the CLI uses), and the naming rules the
 * secrets endpoints share (ProjectSecretController). A null or empty value removes the variable.
 * The values go through ProjectSecrets into the region; results and errors carry names only.
 */
class ProjectSecretWriter
{
    /**
     * Validation errors of a change set, keyed by request field ("config.secret_env_vars.NAME").
     *
     * @param  array<array-key, mixed>  $changes
     * @param  array<array-key, mixed>  $plainEnv  the plain variables the project has after the request
     * @return array<string, string>
     */
    public static function errors(?Project $project, array $changes, array $plainEnv, string $field = 'config.secret_env_vars'): array
    {
        $errors = [];
        $names = $project?->exists ? $project->secrets()->pluck('name')->all() : [];

        foreach ($changes as $name => $value) {
            $name = (string) $name;
            $key = "{$field}.{$name}";

            if (self::removes($value)) {
                $names = array_values(array_diff($names, [$name]));

                continue;
            }
            if ($error = self::nameError($name, $plainEnv)) {
                $errors[$key] = $error;

                continue;
            }
            if (! is_string($value)) {
                $errors[$key] = __('validation.string', ['attribute' => $name]);

                continue;
            }
            if (strlen($value) > ProjectSecret::MAX_VALUE_BYTES) {
                $errors[$key] = __('api.secret.value_too_large', ['max' => ProjectSecret::MAX_VALUE_BYTES / 1024]);

                continue;
            }
            $names[] = $name;
        }

        if (count(array_unique($names)) > ProjectSecret::MAX_PER_PROJECT) {
            $errors[$field] = __('api.secret.too_many', ['max' => ProjectSecret::MAX_PER_PROJECT]);
        }

        return $errors;
    }

    /**
     * Same naming rules as plain variables (EnvVars), and no plain variable of the same name.
     *
     * @param  array<array-key, mixed>  $plainEnv
     */
    public static function nameError(string $name, array $plainEnv): ?string
    {
        if (strlen($name) > 255 || ! preg_match(EnvVars::KEY_PATTERN, $name)) {
            return __('api.project.env_key_invalid', ['key' => mb_substr($name, 0, 64)]);
        }
        if (in_array(strtoupper($name), EnvVars::RESERVED, true)) {
            return __('api.project.env_key_reserved', ['key' => $name]);
        }
        if (array_key_exists($name, $plainEnv)) {
            return __('api.secret.name_is_plain', ['key' => $name]);
        }

        return null;
    }

    /**
     * Set and remove the variables of a validated change set (ProjectSecrets: the values go
     * straight into the region, RunR keeps the metadata).
     *
     * @param  array<array-key, mixed>  $changes
     * @param  KnativeRegion|null  $region  the region the values go to when the same request moves
     *                                      the project there
     * @return array{set: list<string>, removed: list<string>} names only
     *
     * @throws SecretBackendException the region did not take the values: nothing was set
     */
    public static function apply(Project $project, array $changes, mixed $userId, ?KnativeRegion $region = null): array
    {
        $values = [];
        $removals = [];
        foreach ($changes as $name => $value) {
            if (self::removes($value)) {
                $removals[] = (string) $name;
            } else {
                $values[(string) $name] = (string) $value;
            }
        }

        $secrets = app(ProjectSecrets::class);
        $set = $secrets->setValues($project, $values, $userId, $region);
        $removed = $secrets->remove($project, $removals);

        return ['set' => $set, 'removed' => $removed];
    }

    /**
     * Why the values of a change set cannot be kept (null: they can): with pass-through they live
     * in the region's cluster, which a project without a region or on a demo region does not have.
     *
     * @param  array<array-key, mixed>  $changes
     */
    public static function clusterError(?KnativeRegion $region, array $changes): ?string
    {
        if (ProjectSecrets::storage() !== ProjectSecrets::STORAGE_REGION) {
            return null;
        }
        if (array_filter($changes, fn ($value) => ! self::removes($value)) === []) {
            return null;
        }
        if ($region === null || $region->is_demo || (string) $region->kubeconfig === '') {
            return __('api.secret.no_cluster', ['region' => $region->name ?? '—']);
        }

        return null;
    }

    /**
     * Names of a change set that remove a variable.
     *
     * @param  array<array-key, mixed>  $changes
     * @return list<string>
     */
    public static function removals(array $changes): array
    {
        $names = [];
        foreach ($changes as $name => $value) {
            if (self::removes($value)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * The plain variables a project has (config.env_vars of its service config).
     *
     * @return array<array-key, mixed>
     */
    public static function plainEnv(Project $project): array
    {
        $config = match ($project->service_type) {
            'caas' => $project->containerConfig,
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            default => null,
        };

        $env = $config?->getAttribute('env_vars');

        return is_array($env) ? $env : [];
    }

    private static function removes(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
