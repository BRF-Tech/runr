<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::set('base_domain', 'runr.sh');
        Setting::set('registration_enabled', 'true');
        Setting::set('gpu_demo_enabled', 'false');
    }
}
