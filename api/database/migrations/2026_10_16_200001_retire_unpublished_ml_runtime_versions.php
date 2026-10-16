<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PyTorch 2.2 / 2.3 and TensorFlow 2.15 / 2.16 (each with its -gpu build) were runtime versions
 * of the local and demo seeders only; their images were never published. They leave the catalog:
 * inactive and no longer a default. Nothing else changes: projects, sandboxes and pools that name
 * one keep their value and their running pods, and take the runtime's default version at their
 * next deploy, like any retired version (ExecutorImage::resolve()). executor:sync adds the
 * published versions (exec-pytorch:2.14, exec-tensorflow:2.21 and their -gpu builds).
 */
return new class extends Migration
{
    private const RETIRED = [
        'pytorch' => ['2.2', '2.2-gpu', '2.3', '2.3-gpu'],
        'tensorflow' => ['2.15', '2.15-gpu', '2.16', '2.16-gpu'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('executor_images')) {
            return;
        }

        foreach (self::RETIRED as $runtime => $versions) {
            DB::table('executor_images')
                ->where('runtime', $runtime)
                ->whereIn('version', $versions)
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('is_default', true))
                ->update(['is_active' => false, 'is_default' => false, 'updated_at' => now()]);
        }
    }

    /** The versions stay retired: their images do not exist. */
    public function down(): void {}
};
