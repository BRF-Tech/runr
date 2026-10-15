<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('auth_providers', 'google_hosted_domain')) {
            return;
        }

        Schema::table('auth_providers', function (Blueprint $table) {
            $table->string('google_hosted_domain')->nullable()->after('oidc_issuer');
            $table->string('microsoft_tenant')->nullable()->after('google_hosted_domain');
            $table->string('gitlab_instance_uri', 500)->nullable()->after('microsoft_tenant');
        });
    }

    public function down(): void
    {
        Schema::table('auth_providers', function (Blueprint $table) {
            $table->dropColumn([
                'google_hosted_domain',
                'microsoft_tenant',
                'gitlab_instance_uri',
            ]);
        });
    }
};
