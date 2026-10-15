<?php

namespace App\Services\SupplyChain;

use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Services\Manifests\BuildManifests;
use App\Services\Runtimes\RuntimeRegistry;

/**
 * sigstore policy-controller configuration of a region (AdmissionPolicyService).
 *
 * The webhook only looks at namespaces labelled policy.sigstore.dev/include=true — RunR labels
 * the region's project namespace, nothing else. In there every container image must pass every
 * ClusterImagePolicy whose globs match it:
 *
 *  - runr-signed-images   <registry>/runr-builds/**, <registry>/runr-runtimes/** (what RunR
 *                         builds) and <registry>/runr-uploads/** (uploaded images, signed after
 *                         their scan) — signed by one of the region's trusted keys (current +
 *                         retired), optionally with an SPDX SBOM attestation
 *  - runr-system-images   RunR's own images (wrapper, queue consumer, Knative queue-proxy, Kaniko,
 *                         built-in and platform runtime images and the toolchain images of their
 *                         WebAssembly builds, AI engine images, Skupper) and the region's extra
 *                         exemptions — static pass. The webhook resolves every tag to a digest on
 *                         its registry before any policy (also for a static pass): an image the
 *                         webhook cannot resolve is refused ("must be an image digest")
 *  - runr-caas-images     CaaS registries of the allow list — static pass ("allowlist") or a
 *                         signature from one of the trusted CaaS keys ("signed")
 *
 * Images no policy matches: allowed when CaaS images are unrestricted ("any"), denied otherwise
 * (no-match-policy). Mode "warn" admits everything and reports violations as warnings.
 */
class AdmissionManifests
{
    public const API_VERSION = 'policy.sigstore.dev/v1beta1';

    public const SIGNED_POLICY = 'runr-signed-images';

    public const SYSTEM_POLICY = 'runr-system-images';

    public const CAAS_POLICY = 'runr-caas-images';

    public const CONFIG_MAP = 'config-policy-controller';

    public static function namespace(): string
    {
        return (string) config('supply_chain.admission.namespace', 'cosign-system');
    }

    public static function namespaceLabel(): string
    {
        return (string) config('supply_chain.admission.namespace_label', 'policy.sigstore.dev/include');
    }

    /** Helm values of the policy-controller release. */
    public static function helmValues(): array
    {
        $values = [
            'webhook' => [
                'failurePolicy' => 'Fail',
                'namespaceSelector' => [
                    'matchExpressions' => [['key' => self::namespaceLabel(), 'operator' => 'In', 'values' => ['true']]],
                ],
            ],
        ];

        $extra = config('supply_chain.admission.extra_args', []);
        if (is_array($extra) && $extra) {
            $values['webhook']['extraArgs'] = $extra;
        }

        return $values;
    }

    /** Policy for images no ClusterImagePolicy matches. */
    public static function noMatchPolicy(RegionSupplyChainSetting $settings): string
    {
        if ($settings->admission_mode === 'warn') {
            return 'warn';
        }

        return $settings->caas_policy === 'any' ? 'allow' : 'deny';
    }

    public static function configMap(RegionSupplyChainSetting $settings): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => ['name' => self::CONFIG_MAP, 'namespace' => self::namespace(), 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
            'data' => ['no-match-policy' => self::noMatchPolicy($settings)],
        ];
    }

    /**
     * ClusterImagePolicies of a region; a policy without its prerequisites (trusted keys, allow
     * list) is left out.
     *
     * @return list<array>
     */
    public static function policies(KnativeRegion $region, RegionSupplyChainSetting $settings): array
    {
        $mode = $settings->admission_mode === 'warn' ? 'warn' : 'enforce';
        $policies = [];

        $keys = $settings->trustedKeys();
        if ($keys && $region->registry_address) {
            $policies[] = self::policy(self::SIGNED_POLICY, $mode, self::signedGlobs($region), array_map(
                fn (array $key) => self::keyAuthority('region-'.substr($key['fingerprint'], 0, 12), $key['public_key'], $settings->admission_require_sbom),
                $keys,
            ));
        }

        $policies[] = self::policy(self::SYSTEM_POLICY, $mode, self::systemGlobs($region, $settings), [
            ['name' => 'runr-system', 'static' => ['action' => 'pass']],
        ]);

        $registries = array_values(array_filter(array_map('trim', $settings->caas_allowed_registries ?? [])));
        if ($settings->caas_policy !== 'any' && $registries) {
            $globs = array_map(fn ($r) => self::registryGlob($r), $registries);

            if ($settings->caas_policy === 'signed') {
                $caasKeys = array_values(array_filter(array_map('trim', $settings->caas_trusted_keys ?? [])));
                $authorities = array_map(
                    fn (string $pem, int $i) => self::keyAuthority('caas-'.substr(SigningKeyService::fingerprint($pem), 0, 12)."-{$i}", $pem, false),
                    $caasKeys,
                    array_keys($caasKeys),
                );
            } else {
                $authorities = [['name' => 'caas-allowlist', 'static' => ['action' => 'pass']]];
            }

            if ($authorities) {
                $policies[] = self::policy(self::CAAS_POLICY, $mode, $globs, $authorities);
            }
        }

        return $policies;
    }

    /** @return list<string> what RunR builds into the region registry, and uploaded images (signed after their scan) */
    public static function signedGlobs(KnativeRegion $region): array
    {
        $registry = rtrim((string) $region->registry_address, '/');

        return ["{$registry}/runr-builds/**", "{$registry}/runr-runtimes/**", "{$registry}/".trim((string) config('images.repository_prefix', 'runr-uploads'), '/').'/**'];
    }

    /** @return list<string> RunR's own images (+ the region's exemptions), unique, sorted */
    public static function systemGlobs(KnativeRegion $region, RegionSupplyChainSetting $settings): array
    {
        $registry = rtrim((string) $region->registry_address, '/');
        $globs = [];

        foreach ((array) config('supply_chain.system_images', []) as $glob) {
            $globs[] = $glob;
            if ($registry) {
                $globs[] = "{$registry}/{$glob}";      // the same images through the Zot pull-through cache
            }
        }

        $images = array_merge(
            [
                (string) config('executor.wrapper_image'),
                BuildManifests::KANIKO_IMAGE,
                BuildManifests::INIT_IMAGE,
                'ghcr.io/brf-tech/runr-queue-consumer:latest',
            ],
            self::runtimeImages(),
        );
        foreach ($images as $image) {
            if ($image === '') {
                continue;
            }
            array_push($globs, ...ImageRef::parse($image)->policyGlobs());
            $mapped = $region->registryImageRef($image);
            if ($mapped !== $image) {
                array_push($globs, ...ImageRef::parse($mapped)->policyGlobs());
            }
        }

        // Engine images of AI model services (vLLM, TGI, TEI, hf-serve) and their helpers: chosen
        // by the platform (config/ai.php); a model service's own image is a CaaS-like image
        foreach ((array) config('ai.images', []) as $image) {
            if (is_string($image) && $image !== '') {
                array_push($globs, ...ImageRef::parse($image)->policyGlobs());
            }
        }

        // Skupper routers of private database connections run in the project namespace
        $globs[] = 'quay.io/skupper/**';

        foreach ($settings->admission_exempt_images ?? [] as $glob) {
            if (is_string($glob) && trim($glob) !== '') {
                $globs[] = trim($glob);
            }
        }

        $globs = array_values(array_unique($globs));
        sort($globs);

        return $globs;
    }

    /**
     * Built-in and platform runtime images, and the toolchain images their WebAssembly builds run
     * (custom runtimes are not exempt: they get built and signed, or refused).
     */
    private static function runtimeImages(): array
    {
        try {
            $registry = app(RuntimeRegistry::class);

            return array_merge($registry->prepullImages(), $registry->wasmBuildImages());
        } catch (\Throwable) {
            return [];
        }
    }

    /** "docker.io/library" → "index.docker.io/library/**", "ghcr.io/acme/" → "ghcr.io/acme/**" */
    public static function registryGlob(string $prefix): string
    {
        $prefix = rtrim(trim($prefix), '/');
        if (str_contains($prefix, '*')) {
            return $prefix;
        }
        if ($prefix === 'docker.io' || str_starts_with($prefix, 'docker.io/')) {
            $prefix = 'index.'.$prefix;
        }

        return "{$prefix}/**";
    }

    private static function keyAuthority(string $name, string $publicKey, bool $requireSbom): array
    {
        $authority = [
            'name' => $name,
            'key' => ['data' => trim($publicKey)."\n", 'hashAlgorithm' => 'sha256'],
        ];
        if ($requireSbom) {
            $authority['attestations'] = [['name' => 'sbom', 'predicateType' => 'spdxjson']];
        }

        return $authority;
    }

    private static function policy(string $name, string $mode, array $globs, array $authorities): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'kind' => 'ClusterImagePolicy',
            'metadata' => ['name' => $name, 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
            'spec' => [
                'mode' => $mode,
                'images' => array_map(fn ($g) => ['glob' => $g], array_values($globs)),
                'authorities' => array_values($authorities),
            ],
        ];
    }
}
