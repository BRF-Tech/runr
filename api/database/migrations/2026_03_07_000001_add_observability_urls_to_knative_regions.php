<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            if (! Schema::hasColumn('knative_regions', 'prometheus_url')) {
                $table->string('prometheus_url')->nullable();
            }
            $table->string('loki_url')->nullable();
            $table->string('loki_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn(['prometheus_url', 'loki_url', 'loki_status']);
        });
    }
};
