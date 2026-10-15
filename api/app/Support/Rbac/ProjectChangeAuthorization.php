<?php

namespace App\Support\Rbac;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Environment variables are their own permission (env.edit) inside the project create / update
 * payloads (config.env_vars, and the secret variables config.secret_env_vars):
 *  - create: projects.create, plus env.edit when variables are set
 *  - update: env.edit when the variables change, projects.update for every other field
 */
class ProjectChangeAuthorization
{
    private const NEUTRAL_KEYS = ['env_vars', 'secret_env_vars'];

    public static function create(FormRequest $request): bool
    {
        if (! empty($request->input('config.env_vars')) || ! empty($request->input('config.secret_env_vars'))) {
            self::require($request, 'env.edit');
        }

        return true;
    }

    public static function update(FormRequest $request): bool
    {
        $project = $request->route('project');
        if (! $project instanceof Project) {
            return true; // route model binding answers 404
        }

        if (($request->has('config.env_vars') && self::envChanged($project, $request->input('config.env_vars')))
            || ! empty($request->input('config.secret_env_vars'))) {
            self::require($request, 'env.edit', $project);
        }

        $config = (array) $request->input('config', []);
        $other = array_diff(array_keys($config), self::NEUTRAL_KEYS);
        if ($other || $request->hasAny(['description', 'knative_region_id'])) {
            self::require($request, 'projects.update', $project);
        }

        return true;
    }

    private static function envChanged(Project $project, mixed $requested): bool
    {
        $config = match ($project->service_type) {
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            'caas' => $project->containerConfig,
            default => null,
        };

        return self::normalize($config?->env_vars) !== self::normalize($requested);
    }

    private static function normalize(mixed $env): array
    {
        $env = is_array($env) ? array_map(fn ($v) => $v === null ? null : (string) $v, $env) : [];
        ksort($env);

        return $env;
    }

    private static function require(FormRequest $request, string $permission, ?Project $project = null): void
    {
        if (! app(Rbac::class)->allows($request->user(), $permission, $project)) {
            throw new AccessDeniedHttpException(__('rbac.forbidden', ['permission' => $permission]));
        }
    }
}
