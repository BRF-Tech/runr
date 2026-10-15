<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\IngressTrust;
use App\Services\Helpers\TraefikImage;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\HelmReleaseUpgrade;
use App\Services\RegionReconcile\ReconcileStep;

/**
 * Region Traefik image (0.5.0): the region Traefik runs its image from the reqs mirror
 * (TraefikImage, ghcr.io/runrsh/reqs/traefik) instead of Docker Hub, whose anonymous pull limit
 * three replicas — and every node that schedules one later — run into on a shared address.
 *
 * Only a Traefik RunR installed (Helm release "traefik" in the serving namespace), and only when
 * it runs the same Traefik version from another registry: the image moves, the version does
 * not (a Traefik of an older chart is left alone). Updated like IngressTrustStep: the Helm
 * release, upgraded with its own values reused and only image.registry / image.repository /
 * image.tag set, at the chart version it runs (HelmReleaseUpgrade); the Deployment rolls its
 * Traefik pods once.
 */
final class TraefikImageStep implements ReconcileStep
{
    public function name(): string
    {
        return 'traefik-image';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $lines = [];

        foreach ($cluster->servingNamespaces() as $namespace) {
            $line = $this->reconcileNamespace($cluster, $namespace, $dryRun);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function reconcileNamespace(ClusterGroup $cluster, string $namespace, bool $dryRun): ?string
    {
        $deployment = $cluster->k8s->getResourceStatus('apps/v1', 'Deployment', KnativeConstants::TRAEFIK_RELEASE, $namespace);
        // no Traefik, or not RunR's (ingress-trust reports that one)
        if (! $deployment || ! IngressTrust::isRunrRelease($deployment, $namespace)) {
            return null;
        }

        $containers = $deployment['spec']['template']['spec']['containers'] ?? [];
        $container = collect($containers)->firstWhere('name', 'traefik') ?? ($containers[0] ?? []);
        $current = (string) ($container['image'] ?? '');
        $wanted = TraefikImage::ref();

        if ($current === '' || $current === $wanted) {
            return null;
        }

        $release = $namespace.'/'.KnativeConstants::TRAEFIK_RELEASE;
        if (TraefikImage::version($current) !== TraefikImage::version($wanted)) {
            return "left alone: Traefik {$release} runs {$current}, another Traefik version than {$wanted}";
        }

        return HelmReleaseUpgrade::apply(
            $cluster, $dryRun,
            KnativeConstants::TRAEFIK_RELEASE, $namespace, KnativeConstants::TRAEFIK_CHART,
            KnativeConstants::TRAEFIK_REPO_NAME, KnativeConstants::TRAEFIK_REPO_URL,
            TraefikImage::helmSetJson($wanted),
            "image {$current} → {$wanted}",
            'the Traefik pods roll once',
        );
    }
}
