<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_users.role holds a member's tenant-wide role: a built-in role key (owner, admin,
 * developer, viewer, billing) or a custom role key of the tenant (up to 64 characters).
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->table('tenant_users', function (Blueprint $table) {
            $table->string('role', 64)->default('developer')->change();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('tenant_users', function (Blueprint $table) {
            $table->string('role', 20)->default('member')->change();
        });
    }
};
