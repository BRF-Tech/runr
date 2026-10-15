<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('region_status', 20)->default('active')->after('is_active');
        });

        // Sync existing data: inactive regions → region_status = 'inactive'
        DB::table('knative_regions')
            ->where('is_active', false)
            ->update(['region_status' => 'inactive']);
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn('region_status');
        });
    }
};
