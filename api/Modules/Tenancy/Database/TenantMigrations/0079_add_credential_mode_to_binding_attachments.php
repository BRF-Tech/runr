<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * binding_attachments.credential_mode in the tenant schema, as in the central one
 * (database/migrations/2026_10_21_210002): static, or dynamic Postgres credentials issued by the
 * region's OpenBao (DynamicDbCredentials). The organization's attachments live in its schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('binding_attachments') && ! Schema::hasColumn('binding_attachments', 'credential_mode')) {
            Schema::table('binding_attachments', function (Blueprint $table) {
                $table->string('credential_mode', 16)->default('static');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('binding_attachments') && Schema::hasColumn('binding_attachments', 'credential_mode')) {
            Schema::table('binding_attachments', function (Blueprint $table) {
                $table->dropColumn('credential_mode');
            });
        }
    }
};
