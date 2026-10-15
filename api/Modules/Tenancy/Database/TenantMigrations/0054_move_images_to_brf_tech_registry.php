<?php

use App\Support\Images\RegistryMoveColumns;
use App\Support\Images\RegistryMoveRewriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Tenant copy of database/migrations/2026_10_16_000001_move_images_to_brf_tech_registry.php: the
 * tenant schema's image references under ghcr.io/runrsh/ move to ghcr.io/brf-tech/ (CaaS images
 * and version snapshots, tenant-owned regions, bindings, model services, security reports).
 * Idempotent; works on the tenant schema only (RegistryMoveRewriter qualifies table names with
 * the current schema, the search_path's "public" is never touched).
 */
return new class extends Migration
{
    public function up(): void
    {
        $moved = (new RegistryMoveRewriter($this->getConnection()))->move(RegistryMoveColumns::tenant());

        if ($moved) {
            Log::info('Image references of the tenant schema moved to ghcr.io/brf-tech', $moved);
        }
    }

    /** The references stay under ghcr.io/brf-tech: the same images. */
    public function down(): void {}
};
