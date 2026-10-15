<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('sandbox_xaas_runtime', 40)->default('runc')->change();
            $table->string('sandbox_faas_runtime', 40)->default('runc')->change();
            $table->string('sandbox_caas_runtime', 40)->default('runc')->change();
            $table->string('sandbox_build_runtime', 40)->default('runc')->change();
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('sandbox_xaas_runtime', 20)->default('runc')->change();
            $table->string('sandbox_faas_runtime', 20)->default('runc')->change();
            $table->string('sandbox_caas_runtime', 20)->default('runc')->change();
            $table->string('sandbox_build_runtime', 20)->default('runc')->change();
        });
    }
};
