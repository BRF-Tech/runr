<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * queued_deploys: a production deploy of a version that waits for the version's build — and, for
 * a version without a build of its own, for the runtime image RunR builds in the region — and then
 * goes through the deploy checks of every deploy (App\Services\Projects\QueuedDeploys). A region
 * migration queues one for each project whose version is built again on the new cluster.
 *
 * Every tenant schema gets the same table (Modules/Tenancy/Database/TenantMigrations/0065): the
 * organizations' projects live there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('queued_deploys')) {
            return;
        }

        Schema::create('queued_deploys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->char('version_id', 26);
            // waiting | starting | deployed | failed | cancelled
            $table->string('status', 16)->default('waiting');
            // what queued it, e.g. region-migration
            $table->string('reason', 32)->nullable();
            $table->string('deployed_by');
            $table->string('initiator_id', 64)->nullable();
            $table->json('deploy_config')->nullable();
            // the live deployment it takes over from (it stays live until the deploy starts)
            $table->string('replaces_deployment_id', 26)->nullable();
            // the version's build image before the build it waits for (a migration rollback restores it)
            $table->string('previous_build_image')->nullable();
            $table->string('deployment_id', 26)->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['version_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queued_deploys');
    }
};
