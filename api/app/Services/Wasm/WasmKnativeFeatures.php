<?php

namespace App\Services\Wasm;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;

/**
 * Knative Serving feature flags WebAssembly functions need: a Knative Service of a Wasm runtime
 * carries runtimeClassName (the Wasm RuntimeClass) and a nodeSelector (the nodes with the shim).
 * Knative rejects both pod spec fields unless config-features enables them.
 *
 * The flags are merge-patched — never a rebuilt CR — into the KnativeServing CR when the operator
 * manages Serving (it reconciles config-features from spec.config.features), else into the
 * config-features ConfigMap. Only these keys are written; every other feature stays as it is.
 */
final class WasmKnativeFeatures
{
    public const FEATURES = [
        'kubernetes.podspec-runtimeclassname' => 'enabled',
        'kubernetes.podspec-nodeselector' => 'enabled',
    ];

    private const CR_API = 'operator.knative.dev/v1beta1';

    public static function servingNamespace(KnativeRegion $region): string
    {
        return $region->knative_serving_namespace ?: 'knative-serving';
    }

    /**
     * The flags that are not enabled yet (CR spec.config.features, else the ConfigMap).
     *
     * @return array<string, string>
     */
    public static function missing(KubernetesClient $k8s, string $servingNs): array
    {
        $cr = $k8s->getResourceStatus(self::CR_API, 'KnativeServing', 'knative-serving', $servingNs);
        $current = $cr
            ? ($cr['spec']['config']['features'] ?? [])
            : ($k8s->getResourceStatus('v1', 'ConfigMap', 'config-features', $servingNs)['data'] ?? []);

        return array_filter(self::FEATURES, fn (string $value, string $key) => (string) ($current[$key] ?? '') !== $value, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Enable the flags; returns what was changed ([] = already enabled).
     *
     * @return array<string, string>
     */
    public static function enable(KubernetesClient $k8s, string $servingNs): array
    {
        $missing = self::missing($k8s, $servingNs);
        if ($missing === []) {
            return [];
        }

        $cr = $k8s->getResourceStatus(self::CR_API, 'KnativeServing', 'knative-serving', $servingNs);
        $ok = $cr
            ? $k8s->mergePatch(self::CR_API, 'KnativeServing', 'knative-serving', $servingNs, ['spec' => ['config' => ['features' => self::FEATURES]]])
            : $k8s->strategicMergePatch('v1', 'ConfigMap', 'config-features', $servingNs, ['data' => self::FEATURES]);

        if (! $ok) {
            throw new \RuntimeException('cannot enable the Knative features '.implode(', ', array_keys($missing))
                .' ('.($cr ? "KnativeServing {$servingNs}/knative-serving" : "ConfigMap {$servingNs}/config-features").')');
        }

        return $missing;
    }

    /**
     * Whether the nodeSelector feature must stay enabled for the region (WebAssembly installed or
     * being installed) — KnativeNodeService keeps it when dedicated workers are deselected.
     */
    public static function regionNeedsNodeSelector(?KnativeRegion $region): bool
    {
        return in_array($region?->sandbox_wasm_status, ['ready', 'installing'], true);
    }
}
