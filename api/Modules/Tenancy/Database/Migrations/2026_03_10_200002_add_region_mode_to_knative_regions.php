<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('region_mode', 20)->default('managed')->after('is_demo');
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn('region_mode');
        });
    }
};
