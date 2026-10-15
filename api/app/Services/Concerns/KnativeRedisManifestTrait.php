<?php

namespace App\Services\Concerns;

use App\Services\KubernetesClient;
use App\Services\Manifests\RedisManifests;

trait KnativeRedisManifestTrait
{
    private function buildRedisSecret(string $name, ?string $password): ?array
    {
        return RedisManifests::secret($name, $password);
    }

    private function buildRedisDeployment(string $name, ?string $password): array
    {
        return RedisManifests::deployment($name, self::REDIS_IMAGE, $password);
    }

    private function buildRedisService(string $name): array
    {
        return RedisManifests::service($name);
    }

    private function waitForRedisReady(KubernetesClient $k8s, string $namespace, int $timeout = 120): void
    {
        $ready = $this->waitFor(function () use ($k8s, $namespace) {
            $pods = $k8s->listResources('v1', 'Pod', $namespace, [
                'app.kubernetes.io/name' => 'runr-redis',
            ]);
            if (! $pods || empty($pods['items'])) {
                return false;
            }

            foreach ($pods['items'] as $pod) {
                $phase = $pod['status']['phase'] ?? 'Unknown';
                if ($phase !== 'Running') {
                    return false;
                }
            }

            return true;
        }, $timeout);

        if (! $ready) {
            throw new \RuntimeException('Timeout: Redis pods not ready after '.$timeout.'s');
        }
    }
}
