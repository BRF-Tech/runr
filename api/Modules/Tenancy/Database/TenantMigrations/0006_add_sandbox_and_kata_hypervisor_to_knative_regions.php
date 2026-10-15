<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            // Sandbox runtime statuses
            if (! Schema::hasColumn('knative_regions', 'sandbox_kata_status')) {
                $table->string('sandbox_kata_status', 30)->default('not_installed');
                $table->text('sandbox_kata_status_message')->nullable();
                $table->json('sandbox_kata_hypervisor')->nullable();
                $table->string('sandbox_gvisor_status', 30)->default('not_installed');
                $table->text('sandbox_gvisor_status_message')->nullable();
                $table->string('sandbox_xaas_runtime', 20)->default('runc');
                $table->string('sandbox_faas_runtime', 20)->default('runc');
                $table->string('sandbox_caas_runtime', 20)->default('runc');
                $table->string('sandbox_build_runtime', 20)->default('runc');
            } else {
                $table->json('sandbox_kata_hypervisor')->nullable()->after('sandbox_kata_status_message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            if (Schema::hasColumn('knative_regions', 'sandbox_kata_hypervisor')) {
                $table->dropColumn('sandbox_kata_hypervisor');
            }
        });
    }
};
