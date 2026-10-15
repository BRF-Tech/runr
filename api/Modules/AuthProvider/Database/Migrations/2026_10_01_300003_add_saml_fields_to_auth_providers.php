<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SAML 2.0 provider type (`driver = saml`). Tenant copy: TenantMigrations/0032.
 * The SP private key is stored encrypted (model cast), like the OAuth client secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('auth_providers', 'saml_idp_entity_id')) {
            return;
        }

        Schema::table('auth_providers', function (Blueprint $table) {
            $table->string('saml_idp_entity_id', 500)->nullable();
            $table->string('saml_idp_sso_url', 1000)->nullable();
            $table->text('saml_idp_x509_cert')->nullable();
            $table->string('saml_email_attribute')->nullable();
            $table->string('saml_name_attribute')->nullable();
            $table->boolean('saml_sign_requests')->default(false);
            $table->text('saml_sp_x509_cert')->nullable();
            $table->text('saml_sp_private_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('auth_providers', function (Blueprint $table) {
            $table->dropColumn([
                'saml_idp_entity_id',
                'saml_idp_sso_url',
                'saml_idp_x509_cert',
                'saml_email_attribute',
                'saml_name_attribute',
                'saml_sign_requests',
                'saml_sp_x509_cert',
                'saml_sp_private_key',
            ]);
        });
    }
};
