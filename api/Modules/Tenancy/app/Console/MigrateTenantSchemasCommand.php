<?php

namespace Modules\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Modules\Tenancy\Models\Tenant;
use SharedSupport\TenantMigrationRegistry;

class MigrateTenantSchemasCommand extends Command
{
    protected $signature = 'tenancy:migrate
        {--tenant= : Migrate only a specific tenant by slug}
        {--fresh : Drop all tables and re-run migrations}
        {--seed : Seed after migrating}';

    protected $description = 'Run pending migrations for all (or one) tenant schemas';

    public function handle(): int
    {
        if (! config('tenancy.enabled')) {
            $this->error('TENANCY_ENABLED is not set to true.');

            return self::FAILURE;
        }

        $tenants = $this->resolveTenants();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($tenants as $tenant) {
            $this->info("Migrating tenant: {$tenant->name} ({$tenant->slug})...");

            try {
                $this->migrateTenant($tenant);
                $this->info('  Done.');
            } catch (\Throwable $e) {
                $this->error("  Failed: {$e->getMessage()}");
                $failed++;
            }
        }

        $total = $tenants->count();
        $ok = $total - $failed;

        $this->newLine();
        $this->info("Completed: {$ok}/{$total} tenant(s) migrated successfully.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function resolveTenants(): Collection
    {
        $slug = $this->option('tenant');

        if ($slug) {
            $tenant = Tenant::where('slug', $slug)->first();

            return $tenant ? collect([$tenant]) : collect();
        }

        return Tenant::all();
    }

    protected function migrateTenant(Tenant $tenant): void
    {
        $tenant->run(function () {
            $command = $this->option('fresh') ? 'migrate:fresh' : 'migrate';

            $paths = [module_path('Tenancy', 'Database/TenantMigrations')];
            $paths = array_merge($paths, TenantMigrationRegistry::paths());

            $args = [
                '--path' => $paths,
                '--realpath' => true,
                '--force' => true,
            ];

            if ($this->option('seed') && $command === 'migrate:fresh') {
                $args['--seed'] = true;
            }

            \Artisan::call($command, $args);
            $this->line('    '.trim(\Artisan::output()));
        });

        // Seed separately when not using --fresh
        if ($this->option('seed') && ! $this->option('fresh')) {
            $tenant->run(function () {
                \Artisan::call('db:seed', ['--force' => true]);
                $this->line('    '.trim(\Artisan::output()));
            });
        }
    }
}
