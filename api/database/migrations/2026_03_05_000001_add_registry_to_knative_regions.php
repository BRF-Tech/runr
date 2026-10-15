<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('registry_mode', 20)->nullable()->after('knative_eventing_version');
            $table->string('registry_address')->nullable();
            $table->string('registry_username')->nullable();
            $table->text('registry_password')->nullable();
            $table->string('registry_storage_type', 10)->nullable();
            $table->json('registry_storage_config')->nullable();
            $table->string('registry_status', 30)->nullable();
            $table->text('registry_status_message')->nullable();
            $table->string('registry_namespace', 63)->default('runr-registry');
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn([
                'registry_mode', 'registry_address', 'registry_username',
                'registry_password', 'registry_storage_type', 'registry_storage_config',
                'registry_status', 'registry_status_message', 'registry_namespace',
            ]);
        });
    }
};
