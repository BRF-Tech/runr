<?php

namespace App\Services\Helpers;

/**
 * Dedicated workers: the nodes a region's worker selection gives to projects
 * (KnativeNodeService::applyWorkerSelection()), labelled and tainted
 * runr.dev/dedicated=projects:NoSchedule.
 *
 * RunR's components with a node-bound volume — the registry (Zot), Prometheus, Loki, Tempo and
 * the data services — tolerate that taint and prefer the other nodes (systemPlacement()). A
 * node-bound volume (local-path, k3s's default class, any volume with node affinity) keeps its pod
 * on the node it was provisioned on, and that node can be selected as a dedicated worker later:
 * the taint leaves the running pod alone, but the next pod (a restart, a new pod template, a Helm
 * upgrade) has to start on the same node again.
 */
final class DedicatedWorkers
{
    public const KEY = 'runr.dev/dedicated';

    public const VALUE = 'projects';

    public const EFFECT = 'NoSchedule';

    /** Toleration of the dedicated-worker taint. */
    public static function toleration(): array
    {
        return ['key' => self::KEY, 'operator' => 'Equal', 'value' => self::VALUE, 'effect' => self::EFFECT];
    }

    /** The taint as it reads in a log line: runr.dev/dedicated=projects:NoSchedule */
    public static function taint(): string
    {
        return self::KEY.'='.self::VALUE.':'.self::EFFECT;
    }

    /**
     * Node affinity that prefers a node other than a dedicated worker. A preference only: where
     * every node with room is a dedicated worker, the pod still runs, and a pod whose volume is
     * bound to a dedicated worker starts there.
     */
    public static function preferOtherNodes(): array
    {
        return ['nodeAffinity' => ['preferredDuringSchedulingIgnoredDuringExecution' => [[
            'weight' => 100,
            'preference' => ['matchExpressions' => [['key' => self::KEY, 'operator' => 'NotIn', 'values' => [self::VALUE]]]],
        ]]]];
    }

    /**
     * Placement of a component with a node-bound volume, as pod spec keys (or the Helm values of
     * charts that take them under these names): it tolerates the dedicated-worker taint and
     * prefers the other nodes.
     *
     * @return array{tolerations: list<array>, affinity: array}
     */
    public static function systemPlacement(): array
    {
        return ['tolerations' => [self::toleration()], 'affinity' => self::preferOtherNodes()];
    }

    /** Whether $tolerations (a pod spec's) tolerate the dedicated-worker taint. */
    public static function tolerated(array $tolerations): bool
    {
        foreach ($tolerations as $toleration) {
            if (! is_array($toleration)) {
                continue;
            }
            $effect = (string) ($toleration['effect'] ?? '');
            if ($effect !== '' && $effect !== self::EFFECT) {
                continue;
            }
            $key = (string) ($toleration['key'] ?? '');
            if (($toleration['operator'] ?? 'Equal') === 'Exists') {
                if ($key === '' || $key === self::KEY) {
                    return true;
                }

                continue;
            }
            if ($key === self::KEY && (string) ($toleration['value'] ?? '') === self::VALUE) {
                return true;
            }
        }

        return false;
    }

    /** $tolerations with the dedicated-worker toleration added — as they are when they tolerate it. */
    public static function withToleration(array $tolerations): array
    {
        $tolerations = array_values($tolerations);
        if (! self::tolerated($tolerations)) {
            $tolerations[] = self::toleration();
        }

        return $tolerations;
    }

    /** Whether a v1 Node carries the dedicated-worker taint. */
    public static function isTainted(array $node): bool
    {
        foreach ($node['spec']['taints'] ?? [] as $taint) {
            if (($taint['key'] ?? '') === self::KEY && ($taint['value'] ?? '') === self::VALUE && ($taint['effect'] ?? '') === self::EFFECT) {
                return true;
            }
        }

        return false;
    }
}
