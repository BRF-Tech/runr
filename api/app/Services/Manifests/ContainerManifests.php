<?php

namespace App\Services\Manifests;

use App\Models\ContainerConfig;
use App\Models\Project;
use App\Services\Helpers\InstanceSizes;

class ContainerManifests
{
    public static function prebuilt(
        Project $project,
        object $config,
        $version,
        string $mode,
        string $filename,
        string $handler,
        bool $isCompiled,
    ): array {
        $container = [
            'name' => 'handler',
            'image' => $version->build_image,
            'resources' => self::resources($config),
        ];

        $env = [];

        if (! $isCompiled) {
            $env[] = ['name' => 'RUNR_MODE',     'value' => $mode];
            $env[] = ['name' => 'RUNR_HANDLER',  'value' => $handler];
            $env[] = ['name' => 'RUNR_FILENAME', 'value' => $filename];
        }

        $userEnv = $config->env_vars ?? [];
        if (is_array($userEnv)) {
            foreach ($userEnv as $k => $v) {
                $env[] = ['name' => (string) $k, 'value' => (string) $v];
            }
        }

        if (! empty($env)) {
            $container['env'] = $env;
        }

        if ($mode === 'http') {
            $container['ports'] = [['containerPort' => 8080]];
        }

        return $container;
    }

    public static function code(
        string $image,
        object $config,
        string $mode,
        string $filename,
        string $handler,
    ): array {
        $env = [
            ['name' => 'RUNR_MODE',     'value' => $mode],
            ['name' => 'RUNR_HANDLER',  'value' => $handler],
            ['name' => 'RUNR_FILENAME', 'value' => $filename],
        ];

        $userEnv = $config->env_vars ?? [];
        if (is_array($userEnv)) {
            foreach ($userEnv as $k => $v) {
                $env[] = ['name' => (string) $k, 'value' => (string) $v];
            }
        }

        $container = [
            'name' => 'handler',
            'image' => $image,
            'env' => $env,
            'resources' => self::resources($config),
            'volumeMounts' => [[
                'name' => 'code',
                'mountPath' => '/app/code',
                'readOnly' => true,
            ]],
        ];

        if ($mode === 'http') {
            $container['ports'] = [['containerPort' => 8080]];
        }

        return $container;
    }

    public static function caas(object $config, string $mode): array
    {
        $container = [
            'name' => 'app',
            'image' => $config->image,
            'resources' => self::resources($config, 'caas'),
        ];

        $ports = $config->ports ?? [];
        if (! empty($ports)) {
            $container['ports'] = array_map(fn ($p) => ['containerPort' => (int) $p], $ports);
        } elseif ($mode === 'http') {
            $container['ports'] = [['containerPort' => 80]];
        }

        if (! empty($config->command)) {
            $container['command'] = $config->command;
        }
        if (! empty($config->args)) {
            $container['args'] = $config->args;
        }

        $env = [];
        $userEnv = $config->env_vars ?? [];
        if (is_array($userEnv)) {
            foreach ($userEnv as $k => $v) {
                $env[] = ['name' => (string) $k, 'value' => (string) $v];
            }
        }
        if (! empty($env)) {
            $container['env'] = $env;
        }

        if (! empty($config->image_pull_policy)) {
            $container['imagePullPolicy'] = $config->image_pull_policy;
        }

        return $container;
    }

    /**
     * User container resources, request == limit. A config without a size uses the service
     * type's default (InstanceSizes) — the same fallback quota math uses (ResourceReservation).
     */
    public static function resources(object $config, ?string $serviceType = null): array
    {
        $serviceType ??= $config instanceof ContainerConfig ? 'caas' : 'xaas';
        $defaults = InstanceSizes::defaultFor($serviceType);

        $cpu = $config->cpu ?? $defaults['cpu'];
        $memory = ($config->memory_mb ?? $defaults['memory_mb']).'Mi';

        return [
            'requests' => ['cpu' => $cpu, 'memory' => $memory],
            'limits' => ['cpu' => $cpu, 'memory' => $memory],
        ];
    }
}
