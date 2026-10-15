<?php

namespace App\Services\Concerns;

use App\Models\KnativeRegion;
use App\Services\Contracts\KnativeConstants;
use App\Services\KubernetesClient;

trait KnativeCRTrait
{
    /**
     * Build and apply the KnativeServing custom resource.
     */
    private function applyKnativeServingCR(KubernetesClient $k8s, array $settings, string $namespace = 'knative-serving', ?string $version = null): void
    {
        $spec = [
            'ingress' => [
                'istio' => ['enabled' => false],
            ],
            'config' => [
                'network' => [
                    'ingress-class' => 'traefik.ingress.networking.knative.dev',
                    'domain-template' => '{{.Name}}.{{.Domain}}',
                ],
                'autoscaler' => [
                    'enable-scale-to-zero' => 'true',
                    'scale-to-zero-grace-period' => '30s',
                ],
                // Bounded non-active-revision retention: without this, Knative's defaults leave
                // every past Version/deploy's Revision (and its Deployment/ReplicaSet/PA/Route
                // objects) around indefinitely.
                'gc' => self::configGcValues(),
                // queue-proxy sidecar defaults: 100m / 100 MiB. RunR's revisions
                // raise its CPU limit per revision (KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M);
                // requests stay these values, which quota/capacity math adds per pod.
                'deployment' => self::configDeploymentValues(),
            ],
        ];

        // Init containers + emptyDir always needed (the universal wrapper of runtime plugins is
        // copied into an emptyDir by an init container), tolerations/nodeSelector only with workers
        $spec['config']['features'] = [
            'kubernetes.podspec-init-containers' => 'enabled',
            'kubernetes.podspec-emptydir' => 'enabled',
        ];

        if (! empty($settings['enable_podspec_features'])) {
            $spec['config']['features']['kubernetes.podspec-tolerations'] = 'enabled';
            $spec['config']['features']['kubernetes.podspec-nodeselector'] = 'enabled';
        }

        if ($version) {
            $spec['version'] = ltrim($version, 'v');
        }

        $cr = [
            'apiVersion' => 'operator.knative.dev/v1beta1',
            'kind' => 'KnativeServing',
            'metadata' => [
                'name' => 'knative-serving',
                'labels' => ['app.kubernetes.io/managed-by' => 'runr'],
            ],
            'spec' => $spec,
        ];

        if (! empty($settings['knative_domain'])) {
            $cr['spec']['config']['domain'] = [$settings['knative_domain'] => ''];
        }

        $k8s->applyResource($cr, $namespace);
    }

    /**
     * config-gc ConfigMap values: bounded non-active-revision retention.
     * min-non-active-revisions keeps a handful of recent, likely-to-be-redeployed Revisions
     * regardless of age; retain-since-* bounds the rest by time; max-non-active-revisions is a
     * hard ceiling in case of a burst of deploys.
     *
     * @return array<string, string>
     */
    public static function configGcValues(): array
    {
        return [
            'retain-since-create-time' => '48h',
            'retain-since-last-active-time' => '15h',
            'min-non-active-revisions' => '5',
            'max-non-active-revisions' => '20',
        ];
    }

    /**
     * config-deployment ConfigMap values: the queue-proxy sidecar's requests
     * (KnativeConstants::QUEUE_PROXY_*, what quota/capacity math adds per pod), with limit ==
     * request as the default for any Revision that does not set its own. Every Revision RunR
     * deploys since 0.4.2 sets the CPU limit to KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M through
     * its annotation, so project pods are Burstable (CPU burst only from idle node CPU; memory
     * stays request == limit). The default stays as it is: a change here would make Knative roll
     * every Revision's pods (KnativeServingConfigStep).
     *
     * @return array<string, string>
     */
    public static function configDeploymentValues(): array
    {
        $cpu = KnativeConstants::QUEUE_PROXY_CPU_M.'m';
        $memory = KnativeConstants::QUEUE_PROXY_MEMORY_MB.'Mi';

        return [
            'queue-sidecar-cpu-request' => $cpu,
            'queue-sidecar-cpu-limit' => $cpu,
            'queue-sidecar-memory-request' => $memory,
            'queue-sidecar-memory-limit' => $memory,
        ];
    }

    /**
     * Wait for a Knative CR (KnativeServing) to become ready.
     */
    private function waitForCrReady(
        KubernetesClient $k8s,
        KnativeRegion $region,
        string $kind,
        string $crName,
        string $nsProperty,
        string $defaultNs,
        int $timeout = 180,
    ): void {
        $ns = $region->$nsProperty ?? $defaultNs;
        $this->appendLog($region, "Waiting for {$kind} CR to become ready...");

        $ready = $this->waitFor(
            fn () => $this->isCrReady($k8s, $kind, $crName, $ns),
            $timeout,
        );

        if (! $ready) {
            $this->appendLog($region, "Warning: {$kind} CR not ready, continuing...");
        } else {
            $this->appendLog($region, "{$kind} CR is ready.");
        }
    }
}
