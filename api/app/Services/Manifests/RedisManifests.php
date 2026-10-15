<?php

namespace App\Services\Manifests;

class RedisManifests
{
    public static function secret(string $name, ?string $password): ?array
    {
        if (! $password) {
            return null;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => $name.'-auth',
                'labels' => ['app.kubernetes.io/managed-by' => 'runr'],
            ],
            'type' => 'Opaque',
            'data' => ['password' => base64_encode($password)],
        ];
    }

    public static function deployment(string $name, string $redisImage, ?string $password): array
    {
        $args = ['--port', '6379'];

        if ($password) {
            $args = array_merge($args, ['--requirepass', '$(REDIS_PASSWORD)']);
        }

        $container = [
            'name' => 'redis',
            'image' => $redisImage,
            'args' => $args,
            'ports' => [['containerPort' => 6379]],
            'resources' => [
                'requests' => ['cpu' => '100m', 'memory' => '128Mi'],
                'limits' => ['cpu' => '100m', 'memory' => '128Mi'],
            ],
        ];

        if ($password) {
            $container['env'] = [[
                'name' => 'REDIS_PASSWORD',
                'valueFrom' => [
                    'secretKeyRef' => [
                        'name' => $name.'-auth',
                        'key' => 'password',
                    ],
                ],
            ]];
        }

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => $name,
                'labels' => [
                    'app.kubernetes.io/name' => 'runr-redis',
                    'app.kubernetes.io/managed-by' => 'runr',
                ],
            ],
            'spec' => [
                'replicas' => 1,
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => 'runr-redis']],
                'template' => [
                    'metadata' => ['labels' => ['app.kubernetes.io/name' => 'runr-redis']],
                    'spec' => [
                        'containers' => [$container],
                    ],
                ],
            ],
        ];
    }

    public static function service(string $name): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => $name,
                'labels' => [
                    'app.kubernetes.io/name' => 'runr-redis',
                    'app.kubernetes.io/managed-by' => 'runr',
                ],
            ],
            'spec' => [
                'type' => 'ClusterIP',
                'selector' => ['app.kubernetes.io/name' => 'runr-redis'],
                'ports' => [['port' => 6379, 'targetPort' => 6379]],
            ],
        ];
    }
}
