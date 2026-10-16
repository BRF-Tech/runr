<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'runr:install
        {--demo : Seed demo region and sample projects}
        {--reinstall : Drop all tables and re-run migrations (migrate:fresh)}';

    protected $description = 'Install RunR platform — migrate, seed admin & executor images';

    public function handle(): int
    {
        $reinstall = $this->option('reinstall');
        $demo = $this->option('demo');

        if ($reinstall) {
            $this->warn('Dropping all tables and re-running migrations...');
            $this->call('migrate:fresh', ['--force' => true]);
        } else {
            $this->info('Running migrations...');
            $this->call('migrate', ['--force' => true]);
        }

        $this->info('Seeding admin user & settings...');
        $this->call('db:seed', ['--class' => 'AdminSeeder', '--force' => true]);
        $this->call('db:seed', ['--class' => 'SettingsSeeder', '--force' => true]);

        $this->info('Syncing runtime versions from the image registry...');
        $this->call('executor:sync');

        if ($demo) {
            $this->info('Seeding demo data...');
            $this->call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
        }

        $this->newLine();
        $this->info('RunR installed successfully.'.($demo ? ' (with demo data)' : ''));
        $this->line('  Login: admin / the ADMIN_PASSWORD you set, or the generated password printed above.');

        return 0;
    }
}
