<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSO sign-in handoff (docs/security.md): an OAuth / OIDC / SAML callback redirects to the
 * console with a short-lived one-time code instead of the API token; the console exchanges the
 * code (POST /api/oauth/exchange). Only the SHA-256 of the code is stored, the token encrypted.
 * Central schema (the exchange request has no tenant context).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sign_in_handoffs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code_hash', 64)->unique();
            $table->string('user_id', 64);
            $table->text('token');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_handoffs');
    }
};
