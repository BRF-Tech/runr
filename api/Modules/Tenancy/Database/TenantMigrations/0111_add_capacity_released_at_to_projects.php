<?php

use App\Services\Helpers\StoppedCapacity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_11_11_100001_add_capacity_released_at_to_projects.php:
 * a resumed project holds nothing of what its stop gave back until a deploy admits it again
 * (StoppedCapacity). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', StoppedCapacity::COLUMN)) {
                $table->timestamp(StoppedCapacity::COLUMN)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', StoppedCapacity::COLUMN)) {
                $table->dropColumn(StoppedCapacity::COLUMN);
            }
        });
    }
};
