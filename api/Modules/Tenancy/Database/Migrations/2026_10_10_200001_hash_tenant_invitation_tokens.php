<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant invitation tokens are stored as their SHA-256 hash (docs/security.md): the tokens of
 * existing invitations are hashed, so the links already sent keep working. A plain token is a
 * 64-character random [A-Za-z0-9] string; a stored hash is 64 lowercase hex characters — rows
 * already hashed are left alone, the migration can run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_invitations')) {
            return;
        }

        DB::table('tenant_invitations')->orderBy('id')->select(['id', 'token'])->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if (is_string($row->token) && ! preg_match('/^[0-9a-f]{64}$/', $row->token)) {
                    DB::table('tenant_invitations')->where('id', $row->id)->update(['token' => hash('sha256', $row->token)]);
                }
            }
        });
    }

    /** A hash cannot be turned back into the token; the links already sent keep working. */
    public function down(): void {}
};
