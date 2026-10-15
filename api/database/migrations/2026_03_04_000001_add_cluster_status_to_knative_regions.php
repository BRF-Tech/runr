<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('cluster_status')->default('unknown')->after('knative_status_checked_at');
            $table->text('cluster_status_message')->nullable()->after('cluster_status');
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn(['cluster_status', 'cluster_status_message']);
        });
    }
};
