<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom domains per project. Mirrored for tenant databases by
 * Modules/Tenancy/Database/TenantMigrations/0028_create_project_domains_table.php.
 *
 * A certificate/private key uploaded for a domain is never stored here: it only lives
 * in a kubernetes.io/tls Secret in the region's project namespace (tls_secret_name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253)->unique();
            $table->string('status', 16)->default('pending');
            $table->string('verification_token', 64);
            $table->string('tls_mode', 16)->default('none');
            $table->string('tls_secret_name')->nullable();
            $table->timestamp('tls_expires_at')->nullable();
            $table->text('status_message')->nullable();
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_domains');
    }
};
