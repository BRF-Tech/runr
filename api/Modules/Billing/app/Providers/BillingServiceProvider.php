<?php

namespace Modules\Billing\Providers;

use App\Http\Middleware\NormalizeApiTags;
use App\Mcp\Tools\ToolRegistry;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Bindings\BindingTenancy;
use App\Services\OrgTransfer\Sections\SectionRegistry;
use App\Services\Rightsizing\Savings;
use App\Support\Projects\DeployIntent;
use App\Support\Projects\ProjectAdmission;
use App\Support\Projects\WarmEstimates;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Jobs\CheckCostLimitsJob;
use Modules\Billing\Jobs\CloseBillsJob;
use Modules\Billing\Jobs\DeliverBillingEventsJob;
use Modules\Billing\Listeners\BillingSubscriber;
use Modules\Billing\Mcp\BillingBillGet;
use Modules\Billing\Mcp\BillingBillsList;
use Modules\Billing\Mcp\BillingFixedRequestDecide;
use Modules\Billing\Mcp\BillingFixedRequestsList;
use Modules\Billing\Mcp\BillingPriceSet;
use Modules\Billing\Mcp\BillingPricesGet;
use Modules\Billing\Mcp\BillingQueueConsumersSet;
use Modules\Billing\Mcp\BillingRegionOrganizations;
use Modules\Billing\Mcp\BillingRegionOrganizationSet;
use Modules\Billing\Mcp\BillingRegionsList;
use Modules\Billing\Mcp\BillingUsageGet;
use Modules\Billing\Mcp\ProjectBillingGet;
use Modules\Billing\Mcp\ProjectBillingQuote;
use Modules\Billing\Mcp\ProjectCostLimitGet;
use Modules\Billing\Mcp\ProjectCostLimitSet;
use Modules\Billing\Mcp\ProjectFixedRequest;
use Modules\Billing\Services\Admission;
use Modules\Billing\Services\BillArchive;
use Modules\Billing\Services\BillCalculator;
use Modules\Billing\Services\BillingActivity;
use Modules\Billing\Services\BillingEvents;
use Modules\Billing\Services\BillingHistoryTransferSection;
use Modules\Billing\Services\BillingNotifier;
use Modules\Billing\Services\BillingSettings;
use Modules\Billing\Services\BillService;
use Modules\Billing\Services\CostLimits;
use Modules\Billing\Services\CostLimitsTransferSection;
use Modules\Billing\Services\FixedRequests;
use Modules\Billing\Services\Presenter;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Services\QueueConsumerCharging;
use Modules\Billing\Services\RegionOffers;
use Modules\Billing\Services\Regions;
use Modules\Billing\Services\RegionTypes;
use Modules\Billing\Services\RightsizingPricer;
use Modules\Billing\Services\TenantBillService;
use Modules\Billing\Services\UsageReader;
use Modules\Billing\Services\WarmEstimate;
use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\TenantAwareKnativeRegion;
use Modules\Tenancy\Support\TenantRegionShares;
use SharedSupport\TenantMigrationRegistry;

/**
 * Billing module (docs/billing.md): prices with effective dates, region billing types, fixed
 * resource requests and approvals, per-project monthly bills, platform → tenant bills, markups and
 * region offers, monthly cost limits of pay-as-you-go projects, signed webhook events. Off unless
 * BILLING_ENABLED=true; the core metering runs either way.
 */
class BillingServiceProvider extends ServiceProvider
{
    protected string $name = 'Billing';

    /** Services kept for one request / job (scoped: Octane workers start each request fresh). */
    private const SERVICES = [
        Regions::class, PriceBook::class, RegionTypes::class, UsageReader::class, BillCalculator::class,
        Presenter::class, BillingSettings::class, BillingEvents::class, BillingNotifier::class, BillingActivity::class,
        FixedRequests::class, BillService::class, TenantBillService::class, RegionOffers::class, Admission::class,
        BillArchive::class, CostLimits::class, RightsizingPricer::class, QueueConsumerCharging::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(module_path($this->name, 'Config/config.php'), 'billing');

        foreach (self::SERVICES as $service) {
            $this->app->scoped($service);
        }
    }

    public function boot(): void
    {
        // Allocations end with their project whether billing is on or not: a project deleted or
        // moved while billing is off is not billed when it comes back
        self::wireAllocations();

        // The tables exist before billing is switched on, in the central schema and in every tenant
        // schema (tenancy:migrate): switching billing on later needs no migration of its own. The
        // entrypoint migrates every module's central migrations anyway.
        $this->loadMigrationsFrom(module_path($this->name, 'Database/Migrations'));
        TenantMigrationRegistry::register(module_path($this->name, 'Database/TenantMigrations'));

        if (! config('billing.enabled')) {
            // The days billing is off are not billed: the scheduler records them (BillingActivity)
            $this->app->booted(fn () => $this->app->make(Schedule::class)
                ->call(fn () => BillingActivity::markOff())
                ->hourly()
                ->name('billing-off-marker')
                ->withoutOverlapping(10));

            return;
        }

        $this->loadViewsFrom(module_path($this->name, 'resources/views'), 'billing');

        Route::prefix('api')->group(module_path($this->name, 'routes/api.php'));

        self::wire();

        // MCP (docs/mcp.md): a project's monthly cost limit, billing and fixed resources; the
        // organization's bills, usage, prices, regions, queue consumer charging and fixed requests
        ToolRegistry::extend(
            ProjectCostLimitGet::class, ProjectCostLimitSet::class,
            ProjectBillingGet::class, ProjectBillingQuote::class, ProjectFixedRequest::class,
            BillingBillsList::class, BillingBillGet::class, BillingUsageGet::class,
            BillingPricesGet::class, BillingPriceSet::class, BillingRegionsList::class,
            BillingQueueConsumersSet::class, BillingRegionOrganizations::class, BillingRegionOrganizationSet::class,
            BillingFixedRequestsList::class, BillingFixedRequestDecide::class,
        );
        // Organization export / import: the projects' cost limits (docs/org-export.md)
        SectionRegistry::extend(CostLimitsTransferSection::class, 38);
        // ... and the organization's bills with the run history (read-only on import)
        SectionRegistry::extend(BillingHistoryTransferSection::class, 86);

        NormalizeApiTags::registerTags([
            'Settings' => 'Billing',
            'Price' => 'Billing',
            'Region' => 'Billing',
            'Markup' => 'Billing',
            'RegionOffer' => 'Billing',
            'Bill' => 'Billing',
            'Usage' => 'Billing',
            'Rollup' => 'Billing',
            'MyBills' => 'Billing',
            'FixedRequest' => 'Billing',
            'Event' => 'Billing',
            'PlatformBill' => 'Billing',
            'TenantBill' => 'Billing',
            'LegacyRecord' => 'Billing',
            'ArchivedBill' => 'Billing',
            'ProjectBilling' => 'Billing',
        ]);

        $this->app->booted(fn () => $this->registerSchedule());
    }

    /**
     * Hooks into the core and the Tenancy module while billing is on (also called by the tests,
     * where the module's provider does not boot): deploy guard and config limiter of fixed
     * regions, the price of rightsizing recommendations, acceptance of shared regions, listeners.
     */
    public static function wire(): void
    {
        ProjectAdmission::guardDeploys(fn (Project $project, DeployIntent $intent) => app(Admission::class)->guardDeploy($project, $intent));
        ProjectAdmission::limitConfigs(fn (Project $project, ?string $regionId, array $config) => app(Admission::class)->limitConfig($project, $regionId, $config));
        // The timed warmer's preview: billing type and the cost of its warm hours (docs/scaling.md)
        WarmEstimates::priceWith(fn (?string $regionId, float $vcpuHours, float $memoryGbHours, string $trigger, ?Authenticatable $viewer) => app(WarmEstimate::class)->price($regionId, $vcpuHours, $memoryGbHours, $trigger, $viewer));
        Savings::priceWith(fn (Project $project, array $change) => app(RightsizingPricer::class)->price($project, $change));

        if (config('tenancy.enabled') && class_exists(TenantRegionShares::class)) {
            TenantRegionShares::requireAcceptanceWhen(fn () => true);
        }

        Event::subscribe(BillingSubscriber::class);
    }

    /**
     * Allocations end with their project — deleted, or moved to another region — whether billing
     * is on or off (a no-op where billing never ran: FixedRequests::endForProject()); a deleted
     * project's cost limit goes with it.
     */
    public static function wireAllocations(): void
    {
        Project::deleted(function (Project $project) {
            app(FixedRequests::class)->endForProject((string) $project->id);
            CostLimits::forget((string) $project->id);
        });
        Project::updated(function (Project $project) {
            $previous = $project->getOriginal('knative_region_id');
            if ($project->wasChanged('knative_region_id') && is_string($previous) && $previous !== '') {
                app(FixedRequests::class)->endForProject((string) $project->id, $previous);
            }
        });

        // A deleted region takes the queue consumer choices made for it along (Eloquent fires a
        // model's events under the deleted instance's own class: the region API deletes the
        // Tenancy module's TenantAwareKnativeRegion)
        $forget = fn (KnativeRegion $region) => QueueConsumerCharging::forgetRegion((string) $region->getKey());
        foreach ([KnativeRegion::class, TenantAwareKnativeRegion::class, CentralSyncableRegion::class] as $class) {
            if (class_exists($class)) {
                $class::deleted($forget);
            }
        }
    }

    /** Undo wire() (tests). */
    public static function unwire(): void
    {
        ProjectAdmission::reset();
        WarmEstimates::priceWith(null);
        Savings::reset();
        if (class_exists(TenantRegionShares::class)) {
            TenantRegionShares::requireAcceptanceWhen(null);
        }
    }

    private function registerSchedule(): void
    {
        $schedule = $this->app->make(Schedule::class);

        // Months that ended are closed after the usage of their last day was metered (usage-aggregation);
        // the job holds its own lock while it runs (CloseBillsJob)
        $schedule->job(new CloseBillsJob)
            ->dailyAt('02:30')
            ->name('billing-close')
            ->withoutOverlapping(120);

        // The days billing is on (BillingActivity: days it was off are not billed)
        $schedule->call(fn () => $this->app->make(BillingActivity::class)->touch())
            ->hourly()
            ->name('billing-heartbeat')
            ->withoutOverlapping(10);

        // Monthly cost limits of pay-as-you-go projects against the month so far (metering runs
        // every 5 minutes); the job holds its own lock while it runs (CheckCostLimitsJob)
        $schedule->job(new CheckCostLimitsJob)
            ->everyFifteenMinutes()
            ->name('billing-cost-limits')
            ->withoutOverlapping(30);

        // Retries of webhook deliveries (new events are dispatched when they are recorded)
        $schedule->job(new DeliverBillingEventsJob)
            ->everyMinute()
            ->name('billing-event-delivery')
            ->withoutOverlapping(10);

        $schedule->call(function () {
            BindingTenancy::eachContext(fn () => app(BillingEvents::class)->prune());
        })
            ->dailyAt('03:10')
            ->name('billing-event-retention')
            ->withoutOverlapping(60);
    }
}
