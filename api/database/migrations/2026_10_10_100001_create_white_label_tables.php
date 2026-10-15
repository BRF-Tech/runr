<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White-label (docs/white-label.md), platform-level tables:
 *  - brandings:        platform branding (tenant_id null) and per-tenant overrides (multi-tenant)
 *  - pending_signups:  self-service signups waiting for e-mail verification (no user exists yet)
 *  - secure_settings:  platform secrets set from the admin UI, encrypted with the app key
 *  - users.terms_accepted_at: when the user accepted the terms / privacy policy at signup
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brandings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->unique();
            $table->json('settings')->nullable();
            $table->string('logo_light_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->string('logo_mark_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('email_logo_path')->nullable();
            $table->timestamps();
        });

        Schema::create('pending_signups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email')->unique();
            $table->string('username', 64);
            $table->string('name');
            $table->string('password');
            $table->json('payload')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('send_count')->default(1);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('username');
        });

        Schema::create('secure_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasColumn('users', 'terms_accepted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('terms_accepted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'terms_accepted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('terms_accepted_at');
            });
        }

        Schema::dropIfExists('secure_settings');
        Schema::dropIfExists('pending_signups');
        Schema::dropIfExists('brandings');
    }
};
