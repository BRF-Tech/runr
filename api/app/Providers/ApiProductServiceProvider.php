<?php

namespace App\Providers;

use App\Events\ApiProduct\ApiUsageClosed;
use App\Jobs\ApiProduct\CloseApiUsageJob;
use App\Jobs\ApiProduct\GatewayAuthHealthJob;
use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiKey;
use App\Models\ApiProduct\ApiPlan;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Policies\ApiProductPolicy;
use App\Services\ApiProduct\GatewayAuthRevision;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * API product of the gateways (docs/api-product.md): routes (routes/api-product.php), the
 * policies of consumers and plans, the rate limiter of the regions' gateway-auth callbacks, the
 * revision bumps that make those services fetch changed keys / plans / routes, and the schedule
 * that keeps key / JWT routes closed while gateway-auth is not ready (GatewayAuthHealthJob), closes
 * usage periods (usage events) and prunes old hourly rows.
 */
class ApiProductServiceProvider extends ServiceProvider
{
    /** ApiKey columns whose change the regions do not need to hear about. */
    private const KEY_BOOKKEEPING = ['last_used_at', 'updated_at'];

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/api-product.php'));

        // two pods per region: a sync every 10 s and a report every 30 s each
        RateLimiter::for('gateway-auth', fn (Request $request) => Limit::perMinute(600)->by('gateway-auth:'.($request->route('region') ?: $request->ip())));

        Gate::policy(ApiConsumer::class, ApiProductPolicy::class);
        Gate::policy(ApiPlan::class, ApiProductPolicy::class);

        $this->registerRevisionBumps();
        $this->registerSchedule();
    }

    /** Every change the gateway-auth services enforce moves the revision of the regions concerned. */
    private function registerRevisionBumps(): void
    {
        // null gateway (an organization consumer / plan): every region of the organization
        $bump = fn (?Gateway $gateway) => app(GatewayAuthRevision::class)->bump($gateway?->knative_region_id);

        $consumer = fn (ApiConsumer $consumer) => $bump($consumer->gateway);
        ApiConsumer::saved($consumer);
        ApiConsumer::deleted($consumer);

        $plan = fn (ApiPlan $plan) => $bump($plan->gateway);
        ApiPlan::saved($plan);
        ApiPlan::deleted($plan);

        $route = fn (GatewayRoute $route) => $bump($route->gateway);
        GatewayRoute::saved($route);
        GatewayRoute::deleted($route);

        ApiKey::saved(function (ApiKey $key) use ($bump) {
            if (! $key->wasRecentlyCreated && self::onlyBookkeeping($key)) {
                return;
            }
            $bump($key->consumer->gateway);
        });
        ApiKey::deleted(fn (ApiKey $key) => $bump($key->consumer->gateway));
    }

    private static function onlyBookkeeping(Model $model): bool
    {
        return array_diff(array_keys($model->getChanges()), self::KEY_BOOKKEEPING) === [];
    }

    private function registerSchedule(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            // fail closed: key / JWT routes refuse while gateway-auth is not ready, open once it is
            $schedule->job(new GatewayAuthHealthJob)
                ->everyMinute()
                ->name('gateway-auth-health')
                ->withoutOverlapping(10);

            // the previous hour, once the regions' last reports of it are in (every 30 s)
            $schedule->job(new CloseApiUsageJob(ApiUsageClosed::HOUR))
                ->hourlyAt(5)
                ->name('api-usage-close-hour')
                ->withoutOverlapping(30);

            $schedule->job(new CloseApiUsageJob(ApiUsageClosed::MONTH))
                ->monthlyOn(1, '00:20')
                ->name('api-usage-close-month')
                ->withoutOverlapping(60);

            $schedule->job(new CloseApiUsageJob('prune'))
                ->dailyAt('04:50')
                ->name('api-usage-retention')
                ->withoutOverlapping(120);
        });
    }
}
