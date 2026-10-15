<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // XaaS configs — scale fields + handler
        Schema::table('xaas_configs', function (Blueprint $table) {
            $table->string('handler')->default('handler')->after('filename');
            $table->boolean('scale_to_zero')->default(true)->after('queue_stream');
            $table->unsignedInteger('min_scale')->default(0)->after('scale_to_zero');
            $table->unsignedInteger('max_scale')->default(1)->after('min_scale');
        });

        // FaaS configs — scale fields
        Schema::table('faas_configs', function (Blueprint $table) {
            $table->boolean('scale_to_zero')->default(true)->after('queue_stream');
            $table->unsignedInteger('min_scale')->default(0)->after('scale_to_zero');
            $table->unsignedInteger('max_scale')->default(1)->after('min_scale');
        });

        // Container configs — scale fields
        Schema::table('container_configs', function (Blueprint $table) {
            $table->boolean('scale_to_zero')->default(true)->after('queue_stream');
            $table->unsignedInteger('min_scale')->default(0)->after('scale_to_zero');
            $table->unsignedInteger('max_scale')->default(1)->after('min_scale');
        });

        // Deployments — deploy_config
        Schema::table('deployments', function (Blueprint $table) {
            $table->json('deploy_config')->nullable()->after('deployed_by');
        });
    }

    public function down(): void
    {
        Schema::table('xaas_configs', function (Blueprint $table) {
            $table->dropColumn(['handler', 'scale_to_zero', 'min_scale', 'max_scale']);
        });

        Schema::table('faas_configs', function (Blueprint $table) {
            $table->dropColumn(['scale_to_zero', 'min_scale', 'max_scale']);
        });

        Schema::table('container_configs', function (Blueprint $table) {
            $table->dropColumn(['scale_to_zero', 'min_scale', 'max_scale']);
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn('deploy_config');
        });
    }
};
