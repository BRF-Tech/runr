<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * API token scopes of 0.4.x (projects:read, deployments:write …) become catalog permissions
 * (config/permissions.php legacy_token_abilities). Full-access tokens ('*') stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = config('permissions.legacy_token_abilities', []);

        DB::table('personal_access_tokens')->orderBy('id')->chunkById(500, function ($tokens) use ($legacy) {
            foreach ($tokens as $token) {
                $abilities = json_decode($token->abilities ?? '[]', true) ?: [];
                if (! array_intersect($abilities, array_keys($legacy))) {
                    continue;
                }

                $translated = [];
                foreach ($abilities as $ability) {
                    foreach ($legacy[$ability] ?? [$ability] as $permission) {
                        $translated[$permission] = true;
                    }
                }

                DB::table('personal_access_tokens')->where('id', $token->id)
                    ->update(['abilities' => json_encode(array_keys($translated))]);
            }
        });
    }

    public function down(): void
    {
        // One-way: the old scopes were a subset of what the new names express
    }
};
