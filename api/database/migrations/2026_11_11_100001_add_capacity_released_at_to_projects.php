<?php

use App\Services\Helpers\StoppedCapacity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects.capacity_released_at (docs/architecture.md → Region capacity and admission): set when a
 * stopped project is resumed, cleared when a deploy admits it again (StoppedCapacity::readmit()).
 * Until then the project holds what its stop gave back — in a pay-as-you-go region its capacity
 * reservation, in a fixed region its share of the organization's approved allocations, which the
 * organization's other projects may use meanwhile.
 *
 * Every tenant schema gets the same column (Modules/Tenancy/Database/TenantMigrations/0111).
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
