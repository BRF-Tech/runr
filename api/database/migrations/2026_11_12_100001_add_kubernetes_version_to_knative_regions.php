<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * knative_regions.kubernetes_version: the Kubernetes version of the region's cluster ("v1.34.2",
 * the gitVersion of its /version answer), recorded at the kubeconfig check of the region's
 * creation, of a replaced kubeconfig and of a migration, and refreshed by region:reconcile. It
 * picks the kubectl minor of `kubectl exec` (App\Services\Sandboxes\Exec\KubectlBinary). Null:
 * not read yet (a region of an earlier release until its next reconcile): the newest kubectl.
 *
 * Every tenant schema gets the same column (Modules/Tenancy/Database/TenantMigrations/0112): a
 * tenant's own region records it there, and the copies of platform regions receive it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            if (! Schema::hasColumn('knative_regions', 'kubernetes_version')) {
                $table->string('kubernetes_version', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            if (Schema::hasColumn('knative_regions', 'kubernetes_version')) {
                $table->dropColumn('kubernetes_version');
            }
        });
    }
};
