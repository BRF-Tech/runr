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
            $table->string('sandbox_kata_status', 30)->default('not_installed');
            $table->text('sandbox_kata_status_message')->nullable();
            $table->string('sandbox_gvisor_status', 30)->default('not_installed');
            $table->text('sandbox_gvisor_status_message')->nullable();

            // Per-project-type runtime mapping
            $table->string('sandbox_xaas_runtime', 20)->default('runc');
            $table->string('sandbox_faas_runtime', 20)->default('runc');
            $table->string('sandbox_caas_runtime', 20)->default('runc');
            $table->string('sandbox_build_runtime', 20)->default('runc');
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn([
                'sandbox_kata_status', 'sandbox_kata_status_message',
                'sandbox_gvisor_status', 'sandbox_gvisor_status_message',
                'sandbox_xaas_runtime', 'sandbox_faas_runtime',
                'sandbox_caas_runtime', 'sandbox_build_runtime',
            ]);
        });
    }
};
