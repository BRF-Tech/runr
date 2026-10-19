<?php

namespace Modules\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Collection;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantSchema;

class MigrateTenantSchemasCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'tenancy:migrate
        {--tenant= : Migrate only a specific tenant by slug}
        {--fresh : Drop the tenant schema\'s tables (only those) and run every tenant migration again}
        {--seed : Seed after migrating}
        {--force : Run --fresh in production without asking}';

    protected $description = 'Run pending migrations for all (or one) tenant schemas';

    public function handle(): int
    {
        if (! config('tenancy.enabled')) {
            $this->error('TENANCY_ENABLED is not set to true.');

            return self::FAILURE;
        }

        // --fresh empties tenant databases: never without a confirmation in production
        if ($this->option('fresh') && ! $this->confirmToProceed('This drops every table of the selected tenant schemas.')) {
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
        // Tenancy's tenant migrations and those other modules register (TenantSchema::paths()).
        // --fresh recreates the tenant's schema: migrate:fresh in tenant context would drop the
        // central tables too (the search path is "tenant_<id>, public")
        $output = $this->option('fresh') ? TenantSchema::fresh($tenant) : TenantSchema::migrate($tenant);
        if ($output !== '') {
            $this->line('    '.$output);
        }

        if ($this->option('seed')) {
            TenantSchema::inTenant($tenant, function () {
                \Artisan::call('db:seed', ['--force' => true]);
                $this->line('    '.trim(\Artisan::output()));
            });
        }
    }
}
