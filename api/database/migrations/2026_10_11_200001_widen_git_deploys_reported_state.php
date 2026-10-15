<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * git_deploys.reported_state holds "<commit state>|<deploy status>" (StatusReporter), e.g.
 * "pending|deploying" — 17 characters, one more than the column took: PostgreSQL refused the
 * write ("value too long for type character varying(16)") and every status report of a git
 * deploy failed. Tenant schemas: TenantMigrations/0051.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('git_deploys')) {
            return;
        }

        Schema::table('git_deploys', function (Blueprint $table) {
            $table->string('reported_state', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing again could cut stored values: the wider column stays
    }
};
