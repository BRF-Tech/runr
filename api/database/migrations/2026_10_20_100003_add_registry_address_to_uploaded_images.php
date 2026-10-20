<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * uploaded_images.registry_address: the address of the region registry an upload was pushed into.
 * A region moved to another cluster gets that cluster's registry, which does not have the image
 * (RunR keeps no copy of an upload): the image counts as uploaded to the region again only when
 * its registry is the region's (UploadedImage::inRegistryOf()) — a migration rollback makes the
 * old uploads usable again.
 *
 * Existing rows get their region's registry address; in a region whose move to another cluster is
 * still open (migration_old_state holds the old registry), the rows uploaded before the move get
 * the old cluster's. Central table only: uploads are platform-wide records.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('uploaded_images')) {
            return;
        }

        if (! Schema::hasColumn('uploaded_images', 'registry_address')) {
            Schema::table('uploaded_images', function (Blueprint $table) {
                $table->string('registry_address')->nullable();
            });
        }

        $hasOldState = Schema::hasColumn('knative_regions', 'migration_old_state');
        $regions = DB::table('knative_regions')->get();
        foreach ($regions as $region) {
            $now = (string) ($region->registry_address ?? '');
            if ($now === '') {
                continue;
            }

            $rows = DB::table('uploaded_images')->where('knative_region_id', $region->id)->whereNull('registry_address');

            $old = $hasOldState ? (array) json_decode((string) ($region->migration_old_state ?? ''), true) : [];
            $before = (string) ($old['registry_address'] ?? '');
            $since = $old['moved_at'] ?? ($region->migration_started_at ?? null);
            if ($before !== '' && $before !== $now && $since) {
                $since = Carbon::parse($since)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
                (clone $rows)->where('created_at', '<', $since)->update(['registry_address' => $before]);
            }

            $rows->update(['registry_address' => $now]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('uploaded_images') && Schema::hasColumn('uploaded_images', 'registry_address')) {
            Schema::table('uploaded_images', function (Blueprint $table) {
                $table->dropColumn('registry_address');
            });
        }
    }
};
