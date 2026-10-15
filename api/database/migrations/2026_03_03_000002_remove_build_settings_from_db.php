<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::whereIn('key', [
            'build_server_url',
            'build_callback_host',
            'build_insecure_tls',
        ])->delete();
    }

    public function down(): void
    {
        // The seeder can be run again
    }
};
