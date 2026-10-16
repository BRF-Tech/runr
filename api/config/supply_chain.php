<?php

use App\Support\Images\RegistryMove;

/*
|--------------------------------------------------------------------------
| Software supply chain (docs/supply-chain.md)
|--------------------------------------------------------------------------
|
| Every image RunR builds in a region (function versions, runtime images) goes through one
| Kubernetes Job of the supply-chain tools image (runtime/supply-chain): SBOM with syft
| (SPDX JSON + CycloneDX), the SBOMs attached to the image in the region registry as OCI
| referrers, a Trivy vulnerability scan, a cosign signature with the region's key and the SBOM
| and scan as signed attestations. Per-region switches live in region_supply_chain_settings;
| these are the platform defaults and the images RunR runs for it.
|
*/

return [
    // Master switch of the build-ready hooks (off in the PHPUnit suite: phpunit.xml)
    'enabled' => (bool) env('RUNR_SUPPLY_CHAIN_ENABLED', true),

    // Tools image: syft + trivy + cosign + oras + runr-supply-chain (runtime/supply-chain/Dockerfile)
    'tools_image' => RegistryMove::ref(env('RUNR_SUPPLY_CHAIN_IMAGE', 'ghcr.io/brf-tech/runr/supply-chain:1.0.0')),

    // Namespace of the supply-chain Jobs and of the region signing key (one per region)
    'namespace' => env('RUNR_SUPPLY_CHAIN_NAMESPACE', 'runr-supply-chain'),

    // Kubernetes Secret holding the region cosign key pair (cosign.key / cosign.pub / cosign.password)
    'key_secret' => 'runr-cosign-key',

    'job' => [
        // A large image (PyTorch) with a first DB download needs most of this
        'deadline_seconds' => (int) env('RUNR_SUPPLY_CHAIN_JOB_DEADLINE', 1800),
        'ttl_seconds' => 900,
        'poll_interval_seconds' => 5,
        'resources' => [
            'requests' => ['cpu' => env('RUNR_SUPPLY_CHAIN_CPU_REQUEST', '250m'), 'memory' => env('RUNR_SUPPLY_CHAIN_MEMORY_REQUEST', '512Mi')],
            'limits' => ['cpu' => env('RUNR_SUPPLY_CHAIN_CPU_LIMIT', '2'), 'memory' => env('RUNR_SUPPLY_CHAIN_MEMORY_LIMIT', '3Gi')],
        ],
        // emptyDir for the image layers syft / trivy unpack
        'scratch_size_limit' => env('RUNR_SUPPLY_CHAIN_SCRATCH', '20Gi'),
        // Largest gzip'ed document (SBOM, scan report) the Job hands back in its log; larger ones stay in the registry only
        'max_inline_bytes' => (int) env('RUNR_SUPPLY_CHAIN_MAX_INLINE_BYTES', 6 * 1024 * 1024),
    ],

    'scan' => [
        // Findings kept per report (sorted by severity); the counts always cover every finding
        'max_findings' => (int) env('RUNR_SUPPLY_CHAIN_MAX_FINDINGS', 2000),
        // Re-scan of deployed images, default of a new region setting (hours, 0 = off)
        'rescan_interval_hours' => (int) env('RUNR_SUPPLY_CHAIN_RESCAN_HOURS', 24),
        // Trivy DB sources mirrored into the region registry (scanner DB mode "mirror")
        'db_source' => env('RUNR_TRIVY_DB_SOURCE', 'mirror.gcr.io/aquasec/trivy-db:2'),
        'java_db_source' => env('RUNR_TRIVY_JAVA_DB_SOURCE', 'mirror.gcr.io/aquasec/trivy-java-db:1'),
        // Repository of the mirrored DB in the region registry
        'db_repository' => 'runr-supply-chain/trivy-db:2',
        'java_db_repository' => 'runr-supply-chain/trivy-java-db:1',
        // Mirror refresh interval (hours)
        'db_refresh_hours' => (int) env('RUNR_TRIVY_DB_REFRESH_HOURS', 12),
    ],

    // Admission policy: sigstore policy-controller (one Helm release per region)
    'admission' => [
        'helm_repo_name' => 'sigstore',
        'helm_repo_url' => env('RUNR_POLICY_CONTROLLER_HELM_REPO', 'https://sigstore.github.io/helm-charts'),
        'chart' => 'sigstore/policy-controller',
        'chart_version' => env('RUNR_POLICY_CONTROLLER_CHART_VERSION', '0.10.5'),
        'release' => 'runr-policy-controller',
        'namespace' => env('RUNR_POLICY_CONTROLLER_NAMESPACE', 'cosign-system'),
        // Namespace label the webhook selects (policy-controller default)
        'namespace_label' => 'policy.sigstore.dev/include',
        // Extra webhook flags (chart value webhook.extraArgs), e.g. RUNR_POLICY_CONTROLLER_EXTRA_ARGS="disable-tuf=true"
        'extra_args' => collect(explode(',', (string) env('RUNR_POLICY_CONTROLLER_EXTRA_ARGS', '')))
            ->map(fn ($pair) => array_map('trim', explode('=', $pair, 2)))
            ->filter(fn ($pair) => $pair[0] !== '')
            ->mapWithKeys(fn ($pair) => [$pair[0] => $pair[1] ?? 'true'])
            ->all(),
    ],

    // Image globs every region exempts from signature checks (RunR's own system images). RunR's
    // earlier namespaces stay among them: a workload that keeps its pods across the upgrade
    // (region:reconcile, image-registry) is admitted when Kubernetes recreates one
    'system_images' => [
        RegistryMove::TO.'/**',
        RegistryMove::FLAT.'/**',
        RegistryMove::FROM.'/**',
        'gcr.io/knative-releases/**',
    ],
];
