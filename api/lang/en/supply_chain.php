<?php

return [

    // ── Deploy policy gate ──
    'gate' => [
        'blocked' => 'Deploy blocked by the supply-chain policy of region :region: :reasons.',
        'pending' => 'The security checks of :version are still running (:reasons). Deploy again when they finished.',
        'hint_override' => 'Fix the findings and build again, or deploy with a policy override and a reason.',
        'hint_fix' => 'Fix the findings and build again, or ask an administrator with the policy override permission.',
        'critical' => ':count CRITICAL vulnerabilities (allowed: :limit)',
        'high' => ':count HIGH vulnerabilities (allowed: :limit)',
        'fixable_only' => '(counting only findings with a fixed version)',
        'scan_missing' => 'the image has no vulnerability scan',
        'scan_pending' => 'the vulnerability scan is running',
        'signature_missing' => 'the image is not signed with the region key',
        'signature_untrusted' => 'the image signature is not from a key the region trusts',
        'signature_pending' => 'the image signature is being created',
        'override_reason_required' => 'Give a reason for the policy override (at least 3 characters).',
    ],

    // ── Reports ──
    'report' => [
        'running' => 'A supply-chain run of this version is in progress.',
        'not_applicable' => 'This version has no image of its own to check (it runs the runtime image with its code mounted), or the region does not check it.',
        'none' => 'This version has no supply-chain report.',
        'no_sbom' => 'No SBOM in this format for this image.',
        'unknown_format' => 'Unknown SBOM format (spdx-json or cyclonedx-json).',
        'wasm_skipped' => 'A Spin app (WebAssembly OCI artifact) has no container filesystem for syft and Trivy to read: it is signed with the region key, and the policy gate does not require a scan of it.',
    ],

    // ── Signing key ──
    'signing' => [
        'generating' => 'A key generation for this region is in progress.',
        'no_cluster' => 'The region has no cluster to generate a key in.',
        'none' => 'The region has no signing key.',
    ],

    // ── Scanner DB ──
    'scanner_db' => [
        'no_registry' => 'The region needs a container registry for the scanner DB copy.',
    ],

    // ── Admission ──
    'admission' => [
        'key_required' => 'Generate the region signing key before installing the admission policy.',
        'registry_required' => 'The admission policy needs the region registry.',
    ],

    // ── Validation ──
    'validation' => [
        'public_key' => 'Each trusted key must be a PEM public key (-----BEGIN PUBLIC KEY-----).',
        'registries_required' => 'List at least one registry for this CaaS policy.',
        'keys_required' => 'Add at least one trusted public key for signed CaaS images.',
    ],

    // ── Data residency ──
    'residency' => [
        'denied' => 'Your organization keeps its data in :allowed — region :region cannot be used for :action.',
        'location' => 'location :location',
        'migration_location_required' => 'Region :region is pinned to data location :location. Confirm that the new cluster is in :location (data_location) to migrate it.',
        'migration_location_mismatch' => 'Region :region is pinned to data location :location; the new cluster was given as :given. Region data may not leave its location.',
        'default_not_allowed' => 'The default region must be one of the allowed regions or locations.',
        'nothing_to_update' => 'Nothing to update.',
        'actions' => [
            'project.create' => 'new projects',
            'project.move' => 'moving a project',
            'deploy' => 'deployments',
            'preview' => 'previews',
            'sandbox.create' => 'sandboxes',
            'binding.create' => 'bindings',
            'model_service.deploy' => 'model services',
            'model.snapshot' => 'model downloads',
            'image.upload' => 'image uploads',
            'region.migration' => 'region migration',
        ],
    ],
];
