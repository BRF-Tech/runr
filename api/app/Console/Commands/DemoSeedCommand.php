<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed';

    protected $description = 'Seed demo data with fake regions';

    public function handle(): int
    {
        $this->call('migrate');
        $this->call('db:seed', ['--class' => 'DemoSeeder']);
        $this->info('Demo data seeded.');

        return self::SUCCESS;
    }
}
