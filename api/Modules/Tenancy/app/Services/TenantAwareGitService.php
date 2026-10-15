<?php

namespace Modules\Tenancy\Services;

use App\Services\GitService;

class TenantAwareGitService extends GitService
{
    /**
     * Tenant-aware path: storage/git/{tenant_slug}/ when tenancy is active.
     */
    public function repoPath(): string
    {
        $base = storage_path('git');

        if (function_exists('tenant') && tenant()) {
            return $base.'/'.tenant()->slug;
        }

        return $base;
    }
}
