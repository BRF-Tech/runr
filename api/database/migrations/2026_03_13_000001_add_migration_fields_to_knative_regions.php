<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            // Cluster migration
            $table->string('migration_status', 30)->nullable();
            $table->timestamp('migration_started_at')->nullable();
            $table->string('migration_old_ip')->nullable();
            $table->text('migration_old_kubeconfig')->nullable();

            // Domain migration
            $table->string('domain_migration_status', 30)->nullable();
            $table->timestamp('domain_migration_started_at')->nullable();
            $table->string('domain_migration_old_domain')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn([
                'migration_status', 'migration_started_at',
                'migration_old_ip', 'migration_old_kubeconfig',
                'domain_migration_status', 'domain_migration_started_at',
                'domain_migration_old_domain',
            ]);
        });
    }
};
