<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_model_services.status_reason: why the pods of a deploying (or failed) model service do not
 * start, as Kubernetes names it — CrashLoopBackOff, ImagePullBackOff, ErrImagePull, OOMKilled,
 * Unschedulable … (CheckModelServiceReadyJob); status_message carries the details. Tenant
 * schemas: TenantMigrations/0053.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_model_services') || Schema::hasColumn('ai_model_services', 'status_reason')) {
            return;
        }

        Schema::table('ai_model_services', function (Blueprint $table) {
            $table->string('status_reason', 64)->nullable()->after('status_message');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_model_services') && Schema::hasColumn('ai_model_services', 'status_reason')) {
            Schema::table('ai_model_services', function (Blueprint $table) {
                $table->dropColumn('status_reason');
            });
        }
    }
};
