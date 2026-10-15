<?php

namespace App\Services\Concerns;

use App\Services\KubernetesClient;

trait KnativeNodeTaintTrait
{
    private const TAINT_KEY = 'runr.dev/dedicated';

    private const TAINT_VALUE = 'projects';

    private const LABEL_KEY = 'runr.dev/dedicated';

    private const LABEL_VALUE = 'projects';

    private const ROLE_LABEL = 'node-role.kubernetes.io/knative-worker';

    /**
     * Add or remove the RunR taint + label on a node.
     */
    private function taintNode(KubernetesClient $k8s, string $nodeName, bool $add): void
    {
        if ($add) {
            $node = $k8s->getClusterResource('v1', 'Node', $nodeName);
            $taints = $node['spec']['taints'] ?? [];

            $exists = false;
            foreach ($taints as $t) {
                if (($t['key'] ?? '') === self::TAINT_KEY && ($t['value'] ?? '') === self::TAINT_VALUE) {
                    $exists = true;
                    break;
                }
            }

            if (! $exists) {
                $taints[] = [
                    'key' => self::TAINT_KEY,
                    'value' => self::TAINT_VALUE,
                    'effect' => 'NoSchedule',
                ];
            }

            $patch = [
                'metadata' => [
                    'labels' => [
                        self::LABEL_KEY => self::LABEL_VALUE,
                        self::ROLE_LABEL => '',
                    ],
                ],
                'spec' => [
                    'taints' => $taints,
                ],
            ];
        } else {
            $node = $k8s->getClusterResource('v1', 'Node', $nodeName);
            $taints = $node['spec']['taints'] ?? [];
            $taints = array_values(array_filter($taints, function ($t) {
                return ! (($t['key'] ?? '') === self::TAINT_KEY && ($t['value'] ?? '') === self::TAINT_VALUE);
            }));

            $patch = [
                'metadata' => [
                    'labels' => [
                        self::LABEL_KEY => null,
                        self::ROLE_LABEL => null,
                    ],
                ],
                'spec' => [
                    'taints' => empty($taints) ? null : $taints,
                ],
            ];
        }

        $k8s->strategicMergePatchCluster('v1', 'Node', $nodeName, $patch);
    }
}
