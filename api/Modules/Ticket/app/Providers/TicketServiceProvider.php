<?php

namespace Modules\Ticket\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Ticket\Console\TicketE2eCommand;
use SharedSupport\TenantMigrationRegistry;

class TicketServiceProvider extends ServiceProvider
{
    protected string $name = 'Ticket';

    public function register(): void
    {
        $this->mergeConfigFrom(
            module_path($this->name, 'Config/config.php'),
            'ticket'
        );
    }

    public function boot(): void
    {
        // The tables exist before the module is switched on, centrally and in every tenant schema
        // (tenancy:migrate): switching tickets on later needs no migration of its own
        $this->loadMigrationsFrom(module_path($this->name, 'Database/Migrations'));
        TenantMigrationRegistry::register(module_path($this->name, 'Database/TenantMigrations'));

        if (! config('ticket.enabled')) {
            return;
        }

        Route::prefix('api')
            ->group(module_path($this->name, 'routes/api.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([TicketE2eCommand::class]);
        }
    }
}
