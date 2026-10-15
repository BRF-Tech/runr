<?php

namespace App\Support\Images;

/**
 * Every database column that holds an image reference RunR deploys from, per schema — what the
 * image namespace move (RegistryMove) rewrites. RegistryMoveRewriter walks this list for the data
 * migrations and `php artisan images:move-registry`.
 *
 *   ref        a whole image reference (RegistryMove::ref())
 *   json       a JSON document: every string in it through RegistryMove::text()
 *   text       a text (a runtime.yaml as submitted): RegistryMove::text()
 *   encrypted  a JSON document encrypted at rest (App\Casts\Encrypted:array, SecretCipher)
 *
 * The runtimes table has its own pass (RegistryMoveRewriter::runtimes()): its manifest, the
 * runtime.yaml as submitted and the uploaded files (Dockerfile FROM lines) move together, and the
 * region builds of an unchanged source stay valid.
 */
final class RegistryMoveColumns
{
    public const REF = 'ref';

    public const JSON = 'json';

    public const TEXT = 'text';

    public const ENCRYPTED = 'encrypted';

    /** Columns of tables every schema has (central / single-tenant and each tenant schema). */
    private const SHARED = [
        // CaaS projects (also hosted MCP servers of an image) and their version snapshots
        'container_configs' => ['image' => self::REF],
        'versions' => ['config_snapshot' => self::ENCRYPTED],
        'deployments' => ['deploy_config' => self::JSON],
        // Region settings (also tenant-owned regions): Knative, GPU and model storage settings
        'knative_regions' => [
            'knative_settings' => self::JSON,
            'gpu_settings' => self::JSON,
            'model_storage' => self::JSON,
        ],
        // AI model services: a custom engine image
        'ai_model_services' => ['image' => self::REF],
        // Bindings: settings of a binding and of a region's data service
        'bindings' => ['settings' => self::JSON],
        'binding_region_services' => ['config' => self::JSON],
        // Supply chain: the image a report is about (same digest under the new name)
        'image_security_reports' => ['image_ref' => self::REF],
    ];

    /** Columns of central-only tables. */
    private const CENTRAL_ONLY = [
        // Runtime versions: built-in (executor:sync), platform and custom runtimes
        'executor_images' => ['docker_image' => self::REF],
        // Sandboxes: the image a sandbox (and a warm-pool pod) runs
        'sandboxes' => ['image' => self::REF],
        // Admission policy: exempt image globs, allowed CaaS registries
        'region_supply_chain_settings' => [
            'admission_exempt_images' => self::JSON,
            'caas_allowed_registries' => self::JSON,
        ],
    ];

    /** @return array<string, array<string, string>> table => [column => kind] */
    public static function central(): array
    {
        return self::SHARED + self::CENTRAL_ONLY;
    }

    /** @return array<string, array<string, string>> table => [column => kind] */
    public static function tenant(): array
    {
        return self::SHARED;
    }
}
