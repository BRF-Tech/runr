<?php

namespace App\Services\Manifests;

class PrepullManifests
{
    /**
     * Single init container using nsenter + crictl to pull images sequentially
     * on the host runtime. Avoids 1-container-per-image overhead.
     */
    public static function daemonSet(array $images, bool $workersOnly = false): array
    {
        $lines = [];
        foreach ($images as $img) {
            $safe = escapeshellarg($img);
            $lines[] = "echo \"Pulling: {$safe}\"; crictl pull {$safe} 2>/dev/null || echo \"warn: {$safe}\"";
        }
        $script = implode('; ', $lines).'; echo Done';

        $podSpec = [
            'hostPID' => true,
            'initContainers' => [
                [
                    'name' => 'tune-sysctl',
                    'image' => 'ghcr.io/runrsh/reqs/busybox:latest',
                    'command' => ['nsenter', '--target', '1', '--mount', '--', 'sh', '-c',
                        'sysctl -w fs.inotify.max_user_instances=1024 fs.inotify.max_user_watches=524288',
                    ],
                    'securityContext' => ['privileged' => true],
                    'resources' => [
                        'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                        'limits' => ['memory' => '16Mi'],
                    ],
                ],
                [
                    'name' => 'pull-all',
                    'image' => 'ghcr.io/runrsh/reqs/busybox:latest',
                    'command' => ['nsenter', '--target', '1', '--mount', '--net', '--', 'sh', '-c', $script],
                    'securityContext' => ['privileged' => true],
                    'resources' => [
                        'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                        'limits' => ['memory' => '32Mi'],
                    ],
                ],
            ],
            'containers' => [[
                'name' => 'done',
                'image' => 'ghcr.io/runrsh/reqs/busybox:latest',
                'command' => ['sh', '-c', 'while true; do sleep 3600; done'],
                'resources' => [
                    'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                    'limits' => ['memory' => '16Mi'],
                ],
            ]],
        ];

        ZotManifests::applyWorkerAffinity($podSpec, $workersOnly);

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'DaemonSet',
            'metadata' => [
                'name' => 'runr-image-prepull',
                'labels' => ['app.kubernetes.io/managed-by' => 'runr'],
            ],
            'spec' => [
                'selector' => ['matchLabels' => ['app' => 'runr-image-prepull']],
                'template' => [
                    'metadata' => ['labels' => ['app' => 'runr-image-prepull']],
                    'spec' => $podSpec,
                ],
            ],
        ];
    }
}
