<?php

use App\Services\GlobalProjects\GlobalProjectsSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_22_300002_move_global_build_images_to_region_images.php:
 * the organization's global projects' region builds into version_region_images (tenant 0084).
 */
return new class extends Migration
{
    public function up(): void
    {
        GlobalProjectsSchema::moveBuildImages();
    }

    public function down(): void
    {
        // the rows stay: project_regions.build_image still names what each copy runs
    }
};
