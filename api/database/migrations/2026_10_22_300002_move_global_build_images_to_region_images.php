<?php

use App\Services\GlobalProjects\GlobalProjectsSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * The builds of global projects' regions (project_regions.build_image) into the image store
 * environments use (version_region_images, 2026_10_22_100001): one build per version, region and
 * registry for both (docs/global-projects.md#deploys). Tenant copy: TenantMigrations/0096.
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
