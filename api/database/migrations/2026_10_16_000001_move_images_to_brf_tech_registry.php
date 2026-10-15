<?php

use App\Support\Images\RegistryMoveColumns;
use App\Support\Images\RegistryMoveRewriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * RunR's images are published under ghcr.io/brf-tech (same names, tags and digests as under
 * ghcr.io/runrsh before 0.5.1). Every stored reference under ghcr.io/runrsh/ moves there
 * (App\Support\Images\RegistryMove): runtime versions, runtime manifests and their files, CaaS
 * images and version snapshots, region, binding and supply-chain settings, model services,
 * sandboxes, security reports. Exact prefix only: references into other registries stay.
 * Idempotent (`php artisan images:move-registry` runs the same after the upgrade's rollout).
 * Tenant schemas: Modules/Tenancy/Database/TenantMigrations/0054_move_images_to_brf_tech_registry.php
 */
return new class extends Migration
{
    public function up(): void
    {
        $rewriter = new RegistryMoveRewriter($this->getConnection());
        $moved = $rewriter->move(RegistryMoveColumns::central()) + $rewriter->runtimes();

        if ($moved) {
            Log::info('Image references moved to ghcr.io/brf-tech', $moved);
        }
    }

    /** The references stay under ghcr.io/brf-tech: the same images. */
    public function down(): void {}
};
