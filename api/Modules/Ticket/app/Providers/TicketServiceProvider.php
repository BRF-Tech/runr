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
        if (! config('ticket.enabled')) {
            return;
        }

        $this->loadMigrationsFrom(module_path($this->name, 'Database/Migrations'));

        TenantMigrationRegistry::register(module_path($this->name, 'Database/TenantMigrations'));

        Route::prefix('api')
            ->group(module_path($this->name, 'routes/api.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([TicketE2eCommand::class]);
        }
    }
}
