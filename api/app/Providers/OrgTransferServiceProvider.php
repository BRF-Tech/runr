<?php

namespace App\Providers;

use App\Http\Middleware\NoStoreResponse;
use App\Jobs\OrgTransfer\ExpireOrgTransfersJob;
use App\Services\OrgTransfer\Contracts\ImportAdmission;
use App\Services\OrgTransfer\Contracts\ManualMemberInvitations;
use App\Services\OrgTransfer\Contracts\MemberInvitations;
use App\Services\OrgTransfer\Contracts\NoImportAdmission;
use App\Services\OrgTransfer\OrgTransferProgress;
use App\Services\OrgTransfer\Secrets\ProjectSecretValues;
use App\Services\OrgTransfer\Secrets\SecretValues;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Organization export / import (docs/org-export.md): the /api/org-transfers routes
 * (routes/org-transfer.php), the hourly expiry of packages and abandoned uploads, and the seams
 * other parts bind:
 *
 *  - SecretValues       where secret variable values are read and written: ProjectSecretValues,
 *                       through ProjectSecrets and the region's secret backends (pass-through,
 *                       OpenBao, store references)
 *  - MemberInvitations  invitations of the package's members (the Tenancy module's; none here)
 *  - ImportAdmission    the organization's quota for imported projects (the Tenancy module's)
 *
 * Sections of other features: SectionRegistry::extend().
 */
class OrgTransferServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(SecretValues::class, ProjectSecretValues::class);
        $this->app->bindIf(MemberInvitations::class, ManualMemberInvitations::class);
        $this->app->bindIf(ImportAdmission::class, NoImportAdmission::class);
        // One per job: its broadcast throttle spans the whole export / import
        $this->app->scoped(OrgTransferProgress::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            // no-store: transfer progress, plans and packages are live
            Route::middleware(['api', 'auth:sanctum', NoStoreResponse::class])
                ->prefix('api')
                ->group(base_path('routes/org-transfer.php'));
        }

        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->job(new ExpireOrgTransfersJob)
                ->hourly()
                ->name('org-transfers-expire')
                ->withoutOverlapping(30);
        });
    }
}
