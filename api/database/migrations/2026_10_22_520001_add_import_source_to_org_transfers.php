<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization import straight from where an export wrote its package (docs/org-export.md#import):
 * org_transfers.import_source holds the source of an import that RunR fetches itself — an object
 * binding of the organization or an S3-compatible bucket (type, bucket / binding, key, and what a
 * resumed fetch checks: the object's size and ETag, or the SHA-256 the region computed). Never the
 * credentials of an S3 source: they travel in the fetch job's encrypted queue payload only.
 * Uploaded imports keep it null. Central table only (no tenant schema copy).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('org_transfers') && ! Schema::hasColumn('org_transfers', 'import_source')) {
            Schema::table('org_transfers', function (Blueprint $table) {
                $table->json('import_source')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('org_transfers') && Schema::hasColumn('org_transfers', 'import_source')) {
            Schema::table('org_transfers', function (Blueprint $table) {
                $table->dropColumn('import_source');
            });
        }
    }
};
