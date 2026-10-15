<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->string('build_status')->default('ready')->after('config_snapshot');
            $table->text('build_error')->nullable()->after('build_status');
            $table->string('build_token')->nullable()->after('build_error');
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->boolean('knative_ready')->nullable()->after('deploy_config');
            $table->string('knative_message')->nullable()->after('knative_ready');
            $table->timestamp('ready_at')->nullable()->after('knative_message');
        });
    }

    public function down(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->dropColumn(['build_status', 'build_error', 'build_token']);
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['knative_ready', 'knative_message', 'ready_at']);
        });
    }
};
