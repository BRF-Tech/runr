<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_10_10_000001_create_project_secrets_table.php
 * (secret environment variables of a project, value encrypted at rest).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_secrets')) {
            return;
        }

        Schema::create('project_secrets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->string('name');
            $table->text('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_secrets');
    }
};
