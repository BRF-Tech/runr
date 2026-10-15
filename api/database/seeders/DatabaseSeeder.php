<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $this->call([
            AdminSeeder::class,
            SettingsSeeder::class,
            MlRuntimeSeeder::class,
        ]);
    }
}
