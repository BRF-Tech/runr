<?php

use App\Models\KnativeRegion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('project_namespace', 63)->default('runr')->after('knative_settings');
        });

        // Migrate existing knative_settings->namespace values to project_namespace
        foreach (KnativeRegion::all() as $region) {
            $settings = $region->knative_settings ?? [];
            if (! empty($settings['namespace'])) {
                $region->update(['project_namespace' => $settings['namespace']]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn('project_namespace');
        });
    }
};
