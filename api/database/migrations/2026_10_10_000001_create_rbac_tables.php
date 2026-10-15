<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles and permissions (docs/permissions.md): custom roles, groups, group members and role
 * assignments (organization-wide or per project). The same tables are created in every tenant
 * schema by Modules/Tenancy/Database/TenantMigrations/0040_create_rbac_tables.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbac_roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('permissions');
            $table->timestamps();
        });

        Schema::create('rbac_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('rbac_group_user', function (Blueprint $table) {
            $table->char('group_id', 26);
            $table->unsignedBigInteger('user_id');
            $table->string('source', 16)->default('manual'); // manual | sso
            $table->timestamps();

            $table->primary(['group_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('group_id')->references('id')->on('rbac_groups')->cascadeOnDelete();
        });

        Schema::create('rbac_role_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('subject_type', 16); // user | group
            $table->string('subject_id', 64);
            $table->string('role', 64);
            $table->char('project_id', 26)->nullable(); // null = organization-wide
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('project_id');
            $table->index('role');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_role_assignments');
        Schema::dropIfExists('rbac_group_user');
        Schema::dropIfExists('rbac_groups');
        Schema::dropIfExists('rbac_roles');
    }
};
