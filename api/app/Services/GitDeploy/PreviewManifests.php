<?php

namespace App\Services\GitDeploy;

/**
 * Turns the manifests of a regular deploy (KnativeDeployService::buildManifests) into the ones
 * of a preview environment:
 *  - the Knative Service is renamed to <slug>-pr-<number> and the code ConfigMap to
 *    <slug>-pr-<number>-v<N> (the volume follows);
 *  - the preview scales to zero (no min-scale) and at most to MAX_SCALE pods;
 *  - workloads carry runr/preview-of + runr.dev/preview instead of runr/project-id, so the
 *    project's metrics, logs and status never count a preview (the ConfigMap keeps
 *    runr/project-id: deleting the project sweeps it);
 *  - environment: preview overrides are set over the project's values, RUNR_PREVIEW_PR names the
 *    request; for a fork without the "expose secrets" setting every secret-backed variable
 *    (valueFrom.secretKeyRef, envFrom secretRef) is removed;
 *  - secret env vars (KnativeSecretEnvTrait): the project's <slug>-secret-env Secret becomes the
 *    preview's own <service>-secret-env and the secretKeyRef entries follow — values stay in a
 *    Kubernetes Secret, never in the spec. A fork without "expose secrets" gets no Secret at all.
 * Only ConfigMaps, that Secret and Knative Services are kept: previews exist for HTTP-triggered
 * projects.
 */
final class PreviewManifests
{
    public const MAX_SCALE = 3;

    public const LABEL_PREVIEW = 'runr.dev/preview';

    public const LABEL_PREVIEW_OF = 'runr/preview-of';

    public const LABEL_PROJECT = 'runr/project-id';

    public static function serviceName(string $slug, int $number): string
    {
        return mb_substr($slug, 0, 63 - strlen("-pr-{$number}"))."-pr-{$number}";
    }

    /** The Secret with the secret env vars of a preview service. */
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
        $secrets = [];
        $out = [];

        foreach ($manifests as $manifest) {
            if (($manifest['kind'] ?? null) === 'Secret' && ($manifest['metadata']['name'] ?? null) === $projectSlug.'-secret-env') {
                if ($stripSecrets) {
                    continue;
                }
                $new = self::secretEnvName($serviceName);
                $secrets[$projectSlug.'-secret-env'] = $new;

                $manifest['metadata']['name'] = $new;
                $manifest['metadata']['labels'] = ($manifest['metadata']['labels'] ?? []) + [
                    self::LABEL_PREVIEW => $serviceName,
                    self::LABEL_PREVIEW_OF => $projectId,
                ];
                $out[] = $manifest;

                continue;
            }

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

            $template = $manifest['spec']['template'] ?? [];
            $template['metadata']['labels'] = self::previewLabels($template['metadata']['labels'] ?? [], $projectId, $serviceName, $number);

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
                    $template['spec'][$kind][$i] = self::container($container, $kind === 'containers' ? $envOverrides : [], $stripSecrets, $kind === 'containers' ? $number : null, $secrets);
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

    /** @param array<string, string> $secrets renamed Secrets, old name => new name */
    private static function container(array $container, array $overrides, bool $stripSecrets, ?int $number, array $secrets = []): array
    {
        $env = $container['env'] ?? [];

        foreach ($env as $i => $entry) {
            $ref = $entry['valueFrom']['secretKeyRef']['name'] ?? null;
            if ($ref !== null && isset($secrets[$ref])) {
                $env[$i]['valueFrom']['secretKeyRef']['name'] = $secrets[$ref];
            }
        }

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
