<?php

namespace App\Providers;

use App\Models\User;
use App\Support\HorizonAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Who may open the dashboard: platform administrators, signed in to it with a single-use link
     * (App\Support\HorizonAccess — the console itself uses API tokens, which a browser does not
     * send to /horizon). APP_ENV=local lets everyone in.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn (Request $request): bool => app(HorizonAccess::class)->allows($request));
    }

    /**
     * Register the Horizon gate: platform administrators only (users.is_admin, account enabled).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => app(HorizonAccess::class)->isAdministrator($user));
    }
}
