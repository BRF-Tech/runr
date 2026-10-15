<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same table as central 2026_10_01_200001_create_project_domains_table: custom domains
 * per project. The domain is unique within this tenant database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_domains')) {
            return;
        }

        Schema::create('project_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->string('domain', 253)->unique();
            $table->string('status', 16)->default('pending');
            $table->string('verification_token', 64);
            $table->string('tls_mode', 16)->default('none');
            $table->string('tls_secret_name')->nullable();
            $table->timestamp('tls_expires_at')->nullable();
            $table->text('status_message')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_domains');
    }
};
