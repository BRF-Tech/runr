<?php

namespace App\Services\Environments;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * version_region_images (docs/environments.md#another-region): the image of a built version in the
 * registry of a region it was not built for — an environment in another region than its project,
 * or an environment in a region that moved to a cluster with a registry of its own. RegionImages
 * builds the version there from its code (the same build, into that region's registry) and keeps
 * the image here: one row per version, region and registry address — a region move gives the
 * region another registry (a new row), its rollback the old one back, whose row still names the
 * image that registry holds.
 *
 * Defined once for the central migration (database/migrations/2026_10_22_100001_create_version_region_images.php)
 * and the tenant copy (Modules/Tenancy/Database/TenantMigrations/0084_create_version_region_images.php).
 */
final class RegionImageSchema
{
    public const TABLE = 'version_region_images';

    public static function up(): void
    {
        if (! Schema::hasTable('versions') || Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('version_id')->constrained('versions')->cascadeOnDelete();
            $table->string('knative_region_id', 26)->index();
            // The region's registry address the image is pushed to
            $table->string('registry', 255);
            // building | ready | failed
            $table->string('status', 16);
            // <registry>/runr-builds/<slug>:v<N>; null until the build is submitted
            $table->string('image', 512)->nullable();
            // The build Job in the region's project namespace
            $table->string('job_name', 63)->nullable();
            $table->text('error')->nullable();
            // Checks of the build Job (CheckRegionImageBuildJob)
            $table->unsignedInteger('attempts')->default(0);
            // The submission the checks follow (a newer submission has checks of its own)
            $table->string('token', 40)->nullable();
            $table->timestamps();

            $table->unique(['version_id', 'knative_region_id', 'registry']);
            $table->index(['project_id', 'knative_region_id']);
        });
    }

    public static function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
}
