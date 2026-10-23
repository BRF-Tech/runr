<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in with the user name or the e-mail address in any letter case (App\Support\Auth\LoginIdentifier):
 * indexes on lower(username) and lower(email), so the case-insensitive lookup of a sign-in, of the
 * `auth` rate limiter and of the uniqueness checks (App\Rules\UniqueIgnoringCase) uses an index on
 * large user tables (the platform's users of a multi-tenant installation). Not unique: accounts
 * created before 0.6.0 may differ in case only; the sign-in lets the exactly spelled one in.
 */
return new class extends Migration
{
    private const INDEXES = [
        'users_username_lower_index' => 'lower(username)',
        'users_email_lower_index' => 'lower(email)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $expression) {
            if (! Schema::hasIndex('users', $name)) {
                Schema::table('users', fn (Blueprint $table) => $table->rawIndex($expression, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            if (Schema::hasIndex('users', $name)) {
                Schema::table('users', fn (Blueprint $table) => $table->dropIndex($name));
            }
        }
    }
};
