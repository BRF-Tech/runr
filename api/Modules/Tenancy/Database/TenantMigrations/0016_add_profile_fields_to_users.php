<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add profile fields to tenant users table.
     * Mirrors central 2026_03_16_000001 migration.
     * These fields are synced from central via CentralSyncableUser.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable();
            }
            if (! Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable();
            }
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 32)->nullable();
            }
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable();
            }
            if (! Schema::hasColumn('users', 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable();
            }
            if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'is_disabled')) {
                $table->boolean('is_disabled')->default(false);
            }
            if (! Schema::hasColumn('users', 'disabled_at')) {
                $table->timestamp('disabled_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = ['avatar_path', 'bio', 'phone', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'is_disabled', 'disabled_at'];
            $existing = array_filter($cols, fn ($c) => Schema::hasColumn('users', $c));
            if ($existing) {
                $table->dropColumn($existing);
            }
        });
    }
};
