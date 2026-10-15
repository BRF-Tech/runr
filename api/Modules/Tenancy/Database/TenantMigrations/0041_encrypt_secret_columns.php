<?php

use App\Support\Secrets\SecretColumnEncryptor;
use App\Support\Secrets\SecretColumns;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_10_000002_encrypt_secret_columns.php: the tenant
 * schema's JSON secret columns become text and its plaintext secret values are encrypted with
 * APP_KEY. Idempotent; works on the tenant schema only (SecretColumnEncryptor qualifies table
 * names with the current schema, the search_path's "public" is never touched).
 */
return new class extends Migration
{
    public function up(): void
    {
        $encryptor = new SecretColumnEncryptor($this->getConnection());

        $encryptor->widenColumns(SecretColumns::tenant());
        $encryptor->encrypt(SecretColumns::tenant());
    }

    public function down(): void {}
};
