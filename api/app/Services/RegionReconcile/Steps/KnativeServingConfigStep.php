<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\KnativeInstallService;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use RuntimeException;

/**
 * Brings the KnativeServing CR's RunR-owned spec.config sections up to date on existing regions.
 *
 * JSON merge patch of just these sections — never a re-run of applyKnativeServingCR(): that is a
 * server-side apply with force of a rebuilt CR, which would drop fields RunR set at install and
 * does not rebuild here (spec.version, spec.config.domain, podspec feature flags, …).
 * A merge patch of spec.config.gc only touches the keys listed; every other key stays as it is.
 */
final class KnativeServingConfigStep implements ReconcileStep
{
    private const API_VERSION = 'operator.knative.dev/v1beta1';

    private const CR_NAME = 'knative-serving';

    public function name(): string
    {
        return 'knative-serving-config';
    }

    /**
     * spec.config sections (Knative ConfigMap name without "config-") RunR keeps current on every
     * existing region. Adding a section here = one line; the next `region:reconcile` (every Helm
     * upgrade) merge-patches it into all regions.
     *
     * @return array<string, array<string, string>>
     */
    public static function configPatch(): array
    {
        return [
            'gc' => KnativeInstallService::configGcValues(),
            'deployment' => KnativeInstallService::configDeploymentValues(),
        ];
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $lines = [];

        foreach ($cluster->servingNamespaces() as $ns) {
            $cr = $cluster->k8s->getResourceStatus(self::API_VERSION, 'KnativeServing', self::CR_NAME, $ns);
            if (! $cr) {
                $lines[] = "skipped: KnativeServing {$ns}/".self::CR_NAME.' not found';

                continue;
            }

            $patch = self::outdatedSections($cr['spec']['config'] ?? []);
            if (! $patch) {
                continue;
            }

            if (! $dryRun && ! $cluster->k8s->mergePatch(self::API_VERSION, 'KnativeServing', self::CR_NAME, $ns, ['spec' => ['config' => $patch]])) {
                throw new RuntimeException("merge patch of KnativeServing {$ns}/".self::CR_NAME.' was rejected');
            }

            $lines[] = ($dryRun ? 'would merge-patch' : 'merge-patched')." KnativeServing {$ns}/".self::CR_NAME
                .': spec.config.'.implode(', spec.config.', array_keys($patch));

            if (isset($patch['deployment'])) {
                $lines[] = "note: the config-deployment change makes Knative roll each Revision's pods once (queue-proxy sidecar resources)";
            }
        }

        return $lines;
    }

    /**
     * Sections of configPatch() whose values differ from the CR's current spec.config
     * (values compared as strings — Knative ConfigMap data is always string).
     *
     * @param  array<string, mixed>  $current
     * @return array<string, array<string, string>>
     */
    public static function outdatedSections(array $current): array
    {
        $patch = [];

        foreach (self::configPatch() as $section => $values) {
            $have = is_array($current[$section] ?? null) ? $current[$section] : [];

            foreach ($values as $key => $value) {
                if (! array_key_exists($key, $have) || (string) $have[$key] !== $value) {
                    $patch[$section] = $values;
                    break;
                }
            }
        }

        return $patch;
    }
}
