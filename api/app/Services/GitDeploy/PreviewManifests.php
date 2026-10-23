<?php

namespace App\Services\GitDeploy;

use App\Services\InternalNetwork\InternalNetworkManifests;

/**
 * Turns the manifests of a regular deploy (KnativeDeployService::buildManifests) into the ones
 * of a preview environment:
 *  - the Knative Service is renamed to <slug>-pr-<number> and the code ConfigMap to
 *    <slug>-pr-<number>-v<N> (the volume follows);
 *  - the preview scales to zero (no min-scale) and at most to MAX_SCALE pods;
 *  - workloads carry runr/preview-of + runr.dev/preview instead of runr/project-id, so the
 *    project's metrics, logs and status never count a preview (the ConfigMap keeps
 *    runr/project-id: deleting the project sweeps it); a tenant's preview pods carry runr/tenant
 *    as well, which the region agent's session events pass on;
 *  - environment: preview overrides are set over the project's values, RUNR_PREVIEW_PR names the
 *    request; for a fork without the "expose secrets" setting every secret-backed variable
 *    (valueFrom.secretKeyRef, envFrom secretRef) is removed;
 *  - secret env vars (KnativeSecretEnvTrait): the preview runs in the project's namespace and its
 *    secretKeyRef entries read the project's own Secrets (<slug>-secret-env, <slug>-ext-env) —
 *    the values are in the region once, no copy is made. A fork without "expose secrets" reads
 *    none of them.
 * Only ConfigMaps and Knative Services are kept: previews exist for HTTP-triggered projects; what
 * the secret backends apply (an ExternalSecret and its SecretStores) is the project's deploy's.
 * A Secret <service>-secret-env is what previews of earlier releases had (secretEnvName()): the
 * preview's next deploy and its teardown remove it.
 */
final class PreviewManifests
{
    public const MAX_SCALE = 3;

    public const LABEL_PREVIEW = 'runr.dev/preview';

    public const LABEL_PREVIEW_OF = 'runr/preview-of';

    public const LABEL_PROJECT = 'runr/project-id';

    /** The organization of a tenant's workloads (KnativeManifests::extraLabels in tenant context) */
    public const LABEL_TENANT = 'runr/tenant';

    /**
     * <slug>-pr-<number>, kept to 63 characters. Project slugs have at most 44
     * (Project::MAX_SLUG_LENGTH), but earlier releases allowed longer ones: then the slug is cut.
     * A tenant's project slug ends with -<organization> (Project::slugFor): its cut keeps that end,
     * so the name still says whose it is (an organization's domain alias answers only its own
     * names, RegionDomainAliasService) — <start of the name>-<organization>-pr-<number>. Without
     * the organization (or when it leaves no room) the end of the slug is cut. Pods and events
     * find the project by the runr/preview-of label (its id) and the tenant by runr/tenant, never
     * by parsing the name; a preview keeps the name it was created with (PreviewService).
     */
    public static function serviceName(string $slug, int $number, ?string $organization = null): string
    {
        $suffix = "-pr-{$number}";
        if (strlen($slug.$suffix) <= 63) {
            return $slug.$suffix;
        }

        $end = $organization !== null && $organization !== '' && str_ends_with($slug, '-'.$organization) ? '-'.$organization : '';
        $room = 63 - strlen($suffix) - strlen($end);
        if ($end === '' || $room < 1) {
            return mb_substr($slug, 0, 63 - strlen($suffix)).$suffix;
        }

        return rtrim(substr($slug, 0, $room), '-').$end.$suffix;
    }

    /** The Secret with a copy of the secret env vars that previews of earlier releases had. */
    public static function secretEnvName(string $serviceName): string
    {
        return $serviceName.'-secret-env';
    }

    /**
     * @param  list<array>  $manifests
     * @param  array<string, string>  $envOverrides
     * @return list<array>
     */
    public static function transform(
        array $manifests,
        string $projectId,
        string $projectSlug,
        string $serviceName,
        int $number,
        array $envOverrides = [],
        bool $stripSecrets = false,
    ): array {
        $renamed = [];
        $out = [];

        foreach ($manifests as $manifest) {
            if (($manifest['kind'] ?? null) === 'ConfigMap') {
                $old = (string) ($manifest['metadata']['name'] ?? '');
                $new = str_starts_with($old, $projectSlug.'-v')
                    ? $serviceName.substr($old, strlen($projectSlug))
                    : $serviceName.'-'.$old;
                $renamed[$old] = $new;

                $manifest['metadata']['name'] = $new;
                $manifest['metadata']['labels'] = ($manifest['metadata']['labels'] ?? []) + [
                    self::LABEL_PREVIEW => $serviceName,
                    self::LABEL_PREVIEW_OF => $projectId,
                ];
                $out[] = $manifest;
            }
        }

        foreach ($manifests as $manifest) {
            if (($manifest['kind'] ?? null) !== 'Service' || ! str_starts_with((string) ($manifest['apiVersion'] ?? ''), 'serving.knative.dev/')) {
                continue;
            }

            $manifest['metadata']['name'] = $serviceName;
            $manifest['metadata']['labels'] = self::previewLabels($manifest['metadata']['labels'] ?? [], $projectId, $serviceName, $number);
            $manifest['metadata']['annotations'] = ($manifest['metadata']['annotations'] ?? []) + ['runr.sh/preview' => (string) $number];
            // the project's policy on the internal network, without its runr.internal host
            if (isset($manifest['metadata']['annotations'][InternalNetworkManifests::ANNOTATION_KIND])) {
                $manifest['metadata']['annotations'][InternalNetworkManifests::ANNOTATION_KIND] = 'preview';
            }

            $template = $manifest['spec']['template'] ?? [];
            $template['metadata']['labels'] = self::previewLabels($template['metadata']['labels'] ?? [], $projectId, $serviceName, $number);
            // The organization on the pods too: the region agent names it in the preview's session
            // events — a Service name that had to shorten the slug lost the tenant's slug
            $tenant = $manifest['metadata']['labels'][self::LABEL_TENANT] ?? null;
            if (is_string($tenant) && $tenant !== '') {
                $template['metadata']['labels'][self::LABEL_TENANT] = $tenant;
            }

            $annotations = $template['metadata']['annotations'] ?? [];
            unset($annotations['autoscaling.knative.dev/min-scale']);
            $max = (int) ($annotations['autoscaling.knative.dev/max-scale'] ?? 0);
            $annotations['autoscaling.knative.dev/max-scale'] = (string) ($max > 0 ? min($max, self::MAX_SCALE) : self::MAX_SCALE);
            $template['metadata']['annotations'] = $annotations;

            foreach ($template['spec']['volumes'] ?? [] as $i => $volume) {
                $cm = $volume['configMap']['name'] ?? null;
                if ($cm !== null && isset($renamed[$cm])) {
                    $template['spec']['volumes'][$i]['configMap']['name'] = $renamed[$cm];
                }
            }

            foreach (['containers', 'initContainers'] as $kind) {
                foreach ($template['spec'][$kind] ?? [] as $i => $container) {
                    $template['spec'][$kind][$i] = self::container($container, $kind === 'containers' ? $envOverrides : [], $stripSecrets, $kind === 'containers' ? $number : null);
                }
            }

            $manifest['spec']['template'] = $template;
            $out[] = $manifest;
        }

        return $out;
    }

    /** Whether a container still references a Secret (fork rule check). */
    public static function referencesSecrets(array $manifests): bool
    {
        foreach ($manifests as $manifest) {
            foreach (['containers', 'initContainers'] as $kind) {
                foreach ($manifest['spec']['template']['spec'][$kind] ?? [] as $container) {
                    foreach ($container['env'] ?? [] as $env) {
                        if (isset($env['valueFrom']['secretKeyRef'])) {
                            return true;
                        }
                    }
                    foreach ($container['envFrom'] ?? [] as $from) {
                        if (isset($from['secretRef'])) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private static function previewLabels(array $labels, string $projectId, string $serviceName, int $number): array
    {
        unset($labels[self::LABEL_PROJECT]);

        return $labels + [
            self::LABEL_PREVIEW_OF => $projectId,
            self::LABEL_PREVIEW => $serviceName,
            'runr/pr' => (string) $number,
        ];
    }

    private static function container(array $container, array $overrides, bool $stripSecrets, ?int $number): array
    {
        $env = $container['env'] ?? [];

        if ($stripSecrets) {
            $env = array_values(array_filter($env, fn ($e) => ! isset($e['valueFrom']['secretKeyRef'])));
            if (isset($container['envFrom'])) {
                $container['envFrom'] = array_values(array_filter($container['envFrom'], fn ($f) => ! isset($f['secretRef'])));
                if (! $container['envFrom']) {
                    unset($container['envFrom']);
                }
            }
        }

        foreach ($overrides as $name => $value) {
            $env = array_values(array_filter($env, fn ($e) => ($e['name'] ?? null) !== $name));
            $env[] = ['name' => (string) $name, 'value' => (string) $value];
        }

        if ($number !== null) {
            $env = array_values(array_filter($env, fn ($e) => ($e['name'] ?? null) !== 'RUNR_PREVIEW_PR'));
            $env[] = ['name' => 'RUNR_PREVIEW_PR', 'value' => (string) $number];
        }

        if ($env) {
            $container['env'] = $env;
        } else {
            unset($container['env']);
        }

        return $container;
    }
}
