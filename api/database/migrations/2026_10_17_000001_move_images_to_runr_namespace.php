<?php

use App\Support\Images\RegistryMove;
use App\Support\Images\RegistryMoveColumns;
use App\Support\Images\RegistryMoveRewriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * RunR's images are published under ghcr.io/brf-tech/runr (same tags and digests as RunR 0.5.1's
 * packages directly under ghcr.io/brf-tech, and 0.5.0's under ghcr.io/runrsh). Every stored
 * reference of the earlier namespaces moves there (App\Support\Images\RegistryMove): runtime
 * versions, runtime manifests and their files, CaaS images and version snapshots, region, binding
 * and supply-chain settings, model services, sandboxes, security reports. Other packages of
 * ghcr.io/brf-tech and references into other registries stay. Idempotent (`php artisan
 * images:move-registry` runs the same after the upgrade's rollout).
 * Tenant schemas: Modules/Tenancy/Database/TenantMigrations/0055_move_images_to_runr_namespace.php
 */
return new class extends Migration
{
    public function up(): void
    {
        $rewriter = new RegistryMoveRewriter($this->getConnection());
        $moved = $rewriter->move(RegistryMoveColumns::central()) + $rewriter->runtimes();

        if ($moved) {
            Log::info('Image references moved to '.RegistryMove::TO, $moved);
        }
    }

    /** The references stay under ghcr.io/brf-tech/runr: the same images. */
    public function down(): void {}
};
