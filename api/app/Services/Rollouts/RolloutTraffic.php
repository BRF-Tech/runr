<?php

namespace App\Services\Rollouts;

use App\Models\Deployment;
use App\Models\DeploymentRollout;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\Helpers\RegionDomains;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;

/**
 * The traffic split of a project's Knative Service during a rollout (docs/deploy-strategies.md,
 * "Deploy strategies"):
 *
 *  - the deploy of a rollout names its revision (spec.template.metadata.name, pinCandidate) — its
 *    traced containers' spans name it too (runr.revision) — and pins spec.traffic: the revision
 *    that serves keeps 100 %, the new one gets 0 % and a tag — green (blue-green) or canary —
 *    whose route is its test URL, <tag>-<slug>.<function domain>
 *  - the rollout moves the shares with a merge patch of spec.traffic (set): canary steps, a
 *    promotion (blue-green keeps the previous revision at 0 % under the tag "previous", warm for
 *    an instant rollback), an abort or a rollback back to the revision that served
 *  - once the rollout lets go of the previous revision the Knative default comes back: all
 *    traffic to the latest ready revision (latest())
 *
 * Revisions named in spec.traffic are routed, so Knative's revision GC never collects them
 * (config-gc, KnativeCRTrait::configGcValues) and registry retention keeps their images
 * (RegistryImageLifecycleService). A direct deploy of a project that rolled out before writes the
 * default traffic block in its apply (withLatestTraffic): spec.traffic may still pin revisions a
 * rollout left, and the apply takes the field over again.
 */
class RolloutTraffic
{
    /** Tag of the new revision per strategy; its route is the test URL. */
    public const TAGS = [
        DeploymentRollout::BLUE_GREEN => 'green',
        DeploymentRollout::CANARY => 'canary',
    ];

    /** Tag of the previous revision while a promoted blue-green rollout keeps it. */
    public const PREVIOUS_TAG = 'previous';

    /**
     * The revision name of a rollout's deployment: <slug>-v<N>-<6 characters of the deployment
     * id> — a DNS label under 63 characters (slugs have at most 44).
     */
    public static function revisionName(Project $project, Version $version, Deployment $deployment): string
    {
        return $project->slug.'-v'.$version->number.'-'.substr(strtolower((string) $deployment->getKey()), -6);
    }

    /**
     * The revision that serves the Knative Service now: $hint when the service routes to it or it
     * is the latest ready one, else the traffic target with the largest share, else the latest
     * ready revision.
     */
    public static function servingRevision(?array $ksvc, ?string $hint = null): ?string
    {
        if (! is_array($ksvc)) {
            return null;
        }

        $targets = array_values(array_filter((array) ($ksvc['status']['traffic'] ?? []), fn ($t) => is_array($t) && ! empty($t['revisionName'])));
        $latest = $ksvc['status']['latestReadyRevisionName'] ?? null;

        if ($hint !== null && $hint !== '' && ($hint === $latest || in_array($hint, array_column($targets, 'revisionName'), true))) {
            return $hint;
        }

        usort($targets, fn ($a, $b) => (int) ($b['percent'] ?? 0) <=> (int) ($a['percent'] ?? 0));
        if ($targets !== [] && (int) ($targets[0]['percent'] ?? 0) > 0) {
            return (string) $targets[0]['revisionName'];
        }

        return is_string($latest) && $latest !== '' ? $latest : null;
    }

    /**
     * The revisions that receive traffic now (share above 0) — what a warmer keeps warm while a
     * rollout splits the traffic. Falls back to the latest ready revision.
     *
     * @return list<string>
     */
    public static function revisionsWithTraffic(?array $ksvc): array
    {
        $revisions = [];
        foreach ((array) ($ksvc['status']['traffic'] ?? []) as $target) {
            if (is_array($target) && (int) ($target['percent'] ?? 0) > 0 && ! empty($target['revisionName'])) {
                $revisions[] = (string) $target['revisionName'];
            }
        }

        if ($revisions === [] && ! empty($ksvc['status']['latestReadyRevisionName'])) {
            $revisions[] = (string) $ksvc['status']['latestReadyRevisionName'];
        }

        return array_values(array_unique($revisions));
    }

    /**
     * The deploy manifests of a rollout's deployment: its revision named $revision, the traffic
     * pinned to $stableRevision with the new revision at 0 % under $tag.
     *
     * @param  list<array>  $manifests
     * @return list<array>
     */
    public static function pinCandidate(array $manifests, string $revision, string $stableRevision, string $tag): array
    {
        foreach ($manifests as $i => $manifest) {
            if (! self::isKnativeService($manifest)) {
                continue;
            }
            $manifests[$i]['spec']['template']['metadata']['name'] = $revision;
            $manifests[$i]['spec']['template'] = self::withRevisionAttribute($manifests[$i]['spec']['template'], $revision);
            $manifests[$i]['spec']['traffic'] = self::split($stableRevision, $revision, 0, $tag);
        }

        return $manifests;
    }

    /**
     * A traced revision's spans carry its name (resource attribute runr.revision, added to the
     * OTEL_RESOURCE_ATTRIBUTES its containers have — ObservabilityPodSpec): the collector keeps it
     * as a span metrics dimension, so a canary is judged by its own requests (RolloutMetrics).
     * Containers without tracing are left as they are.
     */
    public static function withRevisionAttribute(array $template, string $revision): array
    {
        foreach ($template['spec']['containers'] ?? [] as $i => $container) {
            foreach ($container['env'] ?? [] as $j => $env) {
                if (($env['name'] ?? null) !== 'OTEL_RESOURCE_ATTRIBUTES' || ! is_string($env['value'] ?? null)) {
                    continue;
                }
                $attributes = array_values(array_filter(explode(',', $env['value']), fn (string $pair) => $pair !== '' && ! str_starts_with($pair, 'runr.revision=')));
                $attributes[] = 'runr.revision='.rawurlencode($revision);
                $template['spec']['containers'][$i]['env'][$j]['value'] = implode(',', $attributes);
            }
        }

        return $template;
    }

    /**
     * The deploy manifests with Knative's default traffic written out (all to the latest ready
     * revision): a direct deploy of a project whose service a rollout pinned.
     *
     * @param  list<array>  $manifests
     * @return list<array>
     */
    public static function withLatestTraffic(array $manifests): array
    {
        foreach ($manifests as $i => $manifest) {
            if (self::isKnativeService($manifest)) {
                $manifests[$i]['spec']['traffic'] = self::latest();
            }
        }

        return $manifests;
    }

    /** $percent to the new revision (tagged), the rest to the revision that served. */
    public static function split(string $stableRevision, string $revision, int $percent, string $tag): array
    {
        $percent = max(0, min(100, $percent));
        $targets = [];
        if ($percent < 100) {
            $targets[] = ['revisionName' => $stableRevision, 'percent' => 100 - $percent];
        }
        $targets[] = ['revisionName' => $revision, 'percent' => $percent, 'tag' => $tag];

        return $targets;
    }

    /** All traffic to the promoted revision; the previous one kept routable (0 %, tag previous) when given. */
    public static function promoted(string $revision, string $tag, ?string $previousRevision): array
    {
        $targets = [['revisionName' => $revision, 'percent' => 100, 'tag' => $tag]];
        if ($previousRevision !== null && $previousRevision !== $revision) {
            $targets[] = ['revisionName' => $previousRevision, 'percent' => 0, 'tag' => self::PREVIOUS_TAG];
        }

        return $targets;
    }

    /** All traffic to one revision. */
    public static function only(string $revision): array
    {
        return [['revisionName' => $revision, 'percent' => 100]];
    }

    /** Knative's default: all traffic to the latest ready revision. */
    public static function latest(): array
    {
        return [['latestRevision' => true, 'percent' => 100]];
    }

    /** The test URL of a tag: https://<tag>-<slug>.<function domain>; null without a domain. */
    public static function tagUrl(Project $project, string $tag): ?string
    {
        $region = $project->knativeRegion;
        $domain = $region ? RegionDomains::functionDomain($region) : null;

        return $domain ? "https://{$tag}-{$project->slug}.{$domain}" : null;
    }

    /** The project's Knative Service as the cluster has it; null when absent or unreadable. */
    public function read(Project $project): ?array
    {
        $region = $project->knativeRegion;
        if (! $this->clusterOf($region)) {
            return null;
        }

        try {
            return (new KubernetesClient($region->kubeconfig))->getResourceStatus('serving.knative.dev/v1', 'Service', $project->slug, self::namespace($region));
        } catch (\Throwable $e) {
            Log::warning('Rollout: reading the Knative Service failed', ['project' => $project->slug, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Writes spec.traffic of the rollout's project service (merge patch: the list is replaced).
     * Nothing to write — true — when the project left the region the rollout split (a region
     * move) or the region has no cluster (demo).
     */
    public function set(DeploymentRollout $rollout, Project $project, array $targets): bool
    {
        $region = $project->knativeRegion;
        if (($rollout->region_id !== null && $rollout->region_id !== $project->knative_region_id) || ! $this->clusterOf($region)) {
            return true;
        }

        try {
            $ok = (new KubernetesClient($region->kubeconfig))->mergePatch('serving.knative.dev/v1', 'Service', $project->slug, self::namespace($region), ['spec' => ['traffic' => $targets]]);
        } catch (\Throwable $e) {
            Log::warning('Rollout: the traffic could not be written', ['project' => $project->slug, 'rollout' => $rollout->id, 'error' => $e->getMessage()]);

            return false;
        }

        if (! $ok) {
            Log::warning('Rollout: the traffic could not be written', ['project' => $project->slug, 'rollout' => $rollout->id]);
        }

        return $ok;
    }

    /**
     * Keeps a revision warm: its PodAutoscaler gets at least $minScale pods (the min-scale
     * annotation, which the revision's own template cannot change any more). A revision that
     * leaves the routes scales to zero again whatever this says. Best effort.
     */
    public function warm(DeploymentRollout $rollout, Project $project, string $revision, int $minScale = 1): bool
    {
        $region = $project->knativeRegion;
        if (($rollout->region_id !== null && $rollout->region_id !== $project->knative_region_id) || ! $this->clusterOf($region)) {
            return true;
        }

        try {
            return (new KubernetesClient($region->kubeconfig))->mergePatch(
                'autoscaling.internal.knative.dev/v1alpha1',
                'PodAutoscaler',
                $revision,
                self::namespace($region),
                ['metadata' => ['annotations' => ['autoscaling.knative.dev/min-scale' => (string) max(1, $minScale)]]],
            );
        } catch (\Throwable $e) {
            Log::info('Rollout: the previous revision could not be kept warm', ['project' => $project->slug, 'revision' => $revision, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public static function namespace(KnativeRegion $region): string
    {
        return $region->project_namespace ?? $region->name;
    }

    private function clusterOf(?KnativeRegion $region): bool
    {
        return $region !== null && ! $region->is_demo && ! empty($region->kubeconfig);
    }

    private static function isKnativeService(array $manifest): bool
    {
        return ($manifest['kind'] ?? null) === 'Service' && str_starts_with((string) ($manifest['apiVersion'] ?? ''), 'serving.knative.dev/');
    }
}
