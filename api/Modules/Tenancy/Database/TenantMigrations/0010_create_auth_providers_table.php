<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('driver'); // google, github, oidc
            $table->text('client_id');
            $table->text('client_secret');
            $table->json('scopes')->nullable();
            $table->string('oidc_discovery_url')->nullable();
            $table->string('oidc_issuer')->nullable();
            $table->string('google_hosted_domain')->nullable();
            $table->string('microsoft_tenant')->nullable();
            $table->string('gitlab_instance_uri', 500)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('auto_register')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_providers');
    }
};
