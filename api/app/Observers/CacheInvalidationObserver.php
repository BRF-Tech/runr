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
use Illuminate\Database\Eloquent\Model;

class CacheInvalidationObserver
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
            $model instanceof XaasConfig,
            $model instanceof FaasConfig,
            $model instanceof ContainerConfig,
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
