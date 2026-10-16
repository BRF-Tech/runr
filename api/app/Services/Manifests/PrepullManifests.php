<?php

namespace App\Services\Manifests;

class PrepullManifests
{
    /** The region pre-pull at registry setup (ZotPrepullTrait). */
    public const NAME = 'runr-image-prepull';

    /**
     * Single init container using nsenter + crictl to pull images sequentially
     * on the host runtime. Avoids 1-container-per-image overhead.
     *
     * Other pre-pulls (ModelImagePrepull) give their own name, the helper image the region pulls
     * (registry mirror), their nodes ($placement: nodeSelector / affinity / tolerations instead
     * of $workersOnly) and skip the inotify sysctl step of the registry setup.
     */
    public static function daemonSet(
        array $images,
        bool $workersOnly = false,
        string $name = self::NAME,
        ?string $helperImage = null,
        ?array $placement = null,
        bool $tuneSysctl = true,
        array $labels = [],
    ): array {
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
                    'image' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest',
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
                    'image' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest',
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
                'image' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest',
                'command' => ['sh', '-c', 'while true; do sleep 3600; done'],
                'resources' => [
                    'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                    'limits' => ['memory' => '16Mi'],
                ],
            ]],
        ];

        if (! $tuneSysctl) {
            $podSpec['initContainers'] = array_values(array_filter($podSpec['initContainers'], fn (array $c) => $c['name'] !== 'tune-sysctl'));
        }
        if ($helperImage !== null) {
            foreach (['initContainers', 'containers'] as $kind) {
                foreach ($podSpec[$kind] as $i => $container) {
                    $podSpec[$kind][$i]['image'] = $helperImage;
                }
            }
        }

        if ($placement !== null) {
            $podSpec = array_merge($podSpec, $placement);
        } else {
            ZotManifests::applyWorkerAffinity($podSpec, $workersOnly);
        }

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'DaemonSet',
            'metadata' => [
                'name' => $name,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr'] + $labels,
            ],
            'spec' => [
                'selector' => ['matchLabels' => ['app' => $name]],
                'template' => [
                    'metadata' => ['labels' => ['app' => $name]],
                    'spec' => $podSpec,
                ],
            ],
        ];
    }
}
