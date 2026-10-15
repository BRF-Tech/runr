<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RunR has no plans or packages — the free-text `plan` label on tenants
 * (with inconsistent values across the API, CLI and registration flow) is removed.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('tenants', 'plan')) {
            return;
        }

        Schema::connection($this->connection)->table('tenants', function (Blueprint $table) {
            $table->dropColumn('plan');
        });
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('tenants', 'plan')) {
            return;
        }

        Schema::connection($this->connection)->table('tenants', function (Blueprint $table) {
            $table->string('plan', 50)->default('basic')->after('slug');
        });
    }
};
