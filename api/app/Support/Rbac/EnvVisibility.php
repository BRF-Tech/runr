<?php

namespace App\Support\Rbac;

use Illuminate\Http\Request;

/**
 * Environment variables are shown to users with env.view on the project only; everyone else
 * gets env_vars = null and env_vars_hidden = true in configs, version snapshots and deploy configs.
 */
class EnvVisibility
{
    private static int $hidden = 0;

    public static function visible(Request $request, mixed $project): bool
    {
        return self::$hidden === 0 && app(Rbac::class)->allows($request->user(), 'env.view', $project);
    }

    /**
     * Build a payload without environment variables — broadcasts go to channels every member of
     * the organization listens on, whatever their permissions.
     */
    public static function withoutEnv(callable $build): mixed
    {
        self::$hidden++;
        try {
            return $build();
        } finally {
            self::$hidden--;
        }
    }

    /** @param  array<string, mixed>|null  $config */
    public static function mask(Request $request, mixed $project, ?array $config): ?array
    {
        if ($config === null || ! array_key_exists('env_vars', $config) || self::visible($request, $project)) {
            return $config;
        }

        $config['env_vars'] = null;
        $config['env_vars_hidden'] = true;

        return $config;
    }
}
