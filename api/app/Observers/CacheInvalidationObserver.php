<?php

namespace App\Observers;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\DeploymentRollout;
use App\Models\ExecutorImage;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\Setting;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\CacheVersionService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Bumps the response cache versions (App\Http\Middleware\ResponseCache) of what a change touches —
 * after the transaction that made it commits. A bump inside the transaction let a GET in between
 * read the new version with the old rows and cache them under it: a deployment activated inside
 * LiveDeployments::activate() answered "pending" from the cache until something else bumped the
 * project (a redeploy measured 22 s where the deployment was active after 5 s). Outside a
 * transaction the bump happens at once, as before.
 */
class CacheInvalidationObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private CacheVersionService $cacheVersion
    ) {}

    public function created(Model $model): void
    {
        $this->invalidate($model);
    }

    public function updated(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    /**
     * A change of $model written by the query builder — a guarded whereKey()->update(), which fires
     * no model event (a project's stop, resume and readmission, ProjectStopService and
     * StoppedCapacity) — bumped as an Eloquent update bumps it, after its transaction commits.
     */
    public static function touched(Model $model): void
    {
        DB::connection($model->getConnectionName())->afterCommit(fn () => app(self::class)->invalidate($model));
    }

    private function invalidate(Model $model): void
    {
        $this->cacheVersion->bumpMany($this->scopesFor($model));
    }

    private function scopesFor(Model $model): array
    {
        return match (true) {
            $model instanceof Project => [
                'projects',
                CacheVersionService::projectScope((string) $model->id),
                'settings',
            ],
            $model instanceof Version => [
                'projects',
                CacheVersionService::projectScope((string) $model->project_id),
            ],
            $model instanceof Deployment,
            $model instanceof DeploymentRollout => [
                'projects',
                CacheVersionService::projectScope((string) $model->project_id),
            ],
            // a project's size and scaling live here: what it reserves in its region, so the
            // region's answers (settings: GET /knative-regions/{id}/capacity) change with them
            $model instanceof XaasConfig,
            $model instanceof FaasConfig,
            $model instanceof ContainerConfig => [
                'projects',
                CacheVersionService::projectScope((string) $model->project_id),
                'settings',
            ],
            $model instanceof ProjectDomain => [
                'projects',
                CacheVersionService::projectScope((string) $model->project_id),
            ],
            $model instanceof KnativeRegion => [
                'settings',
                'projects',
            ],
            $model instanceof Setting => [
                'settings',
                'projects',
            ],
            $model instanceof ExecutorImage => [
                'settings',
                'projects',
            ],
            default => [],
        };
    }
}
