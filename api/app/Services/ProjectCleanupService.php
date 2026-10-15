<?php

namespace App\Services;

use App\Models\Project;
use App\Services\Bindings\BindingManager;
use App\Services\Skupper\SkupperService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Everything a project leaves outside its database rows: its custom-domain routes and
 * certificates, its Knative Service / CronJob / queue consumer / code ConfigMaps in the region,
 * its private database connections (Skupper), its git and build directories, and its
 * internal-registry images.
 *
 * ProjectObserver::deleting() runs it for a single project delete (failures are logged, the row
 * is deleted anyway — unchanged behaviour); the tenant deletion flow runs it per project and
 * counts the returned failures, so a tenant is only dropped once nothing is left running.
 */
class ProjectCleanupService
{
    public function __construct(
        private KnativeDeployService $deployer,
        private GitService $git,
        private RegistryImageLifecycleService $registry,
    ) {}

    /**
     * @return list<string> what could not be removed from the region (empty = all gone)
     */
    public function cleanup(Project $project): array
    {
        $failures = [];

        // IngressRoutes / Middleware / TLS Secret / Certificate in the region namespace
        try {
            foreach (app(ProjectDomainService::class)->removeAllForProject($project) as $domain) {
                $failures[] = "{$project->slug}: custom domain {$domain} not fully removed from the region";
            }
        } catch (\Throwable $e) {
            $failures[] = "{$project->slug}: custom domains: {$e->getMessage()}";
        }

        try {
            // Whatever state its deployments are in: a pending/failed deployment still runs a ksvc
            if (! $this->deployer->teardownProject($project)) {
                $failures[] = "{$project->slug}: Kubernetes teardown incomplete (see log)";
            }
        } catch (\Throwable $e) {
            $failures[] = "{$project->slug}: {$e->getMessage()}";
        }

        array_push($failures, ...$this->cleanupPrivateConnections($project));

        // Bindings stay; the project's bindings Secret goes, their NetworkPolicies drop the project
        array_push($failures, ...app(BindingManager::class)->cleanupProject($project));

        if ((string) $project->id !== '') {
            // repoPath(): storage/git, or storage/git/{tenant slug} in tenant context (TenantAwareGitService)
            $this->deleteDirectory($this->git->repoPath().'/'.$this->git->projectDir($project));
            $this->deleteDirectory(storage_path("builds/{$project->id}"));
        }
        $this->cleanupRegistry($project);

        if ($failures) {
            Log::warning('Project cleanup incomplete', ['project' => $project->slug, 'failures' => $failures]);
        }

        return $failures;
    }

    /**
     * Skupper Listener, Link / AccessToken and Secrets of the project's private
     * database connections in the region's project namespace, then the rows.
     *
     * @return list<string>
     */
    private function cleanupPrivateConnections(Project $project): array
    {
        $failures = [];

        try {
            $bindings = $project->privateDatabaseBindings()->get();
            if ($bindings->isEmpty()) {
                return [];
            }

            $region = $project->knativeRegion;
            if ($region?->kubeconfig) {
                $skupper = app(SkupperService::class);
                foreach ($bindings as $binding) {
                    try {
                        $skupper->remove($binding, $region);
                    } catch (\Throwable $e) {
                        $failures[] = "{$project->slug}: private connection {$binding->resource_name}: {$e->getMessage()}";
                    }
                }
            }

            $project->privateDatabaseBindings()->delete();
        } catch (\Throwable $e) {
            $failures[] = "{$project->slug}: private connections: {$e->getMessage()}";
        }

        return $failures;
    }

    private function deleteDirectory(string $path): void
    {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }

    /**
     * An internal-registry region keeps a project's build images (runr-builds/{slug})
     * around forever otherwise. A registry cleanup failure never blocks the delete.
     */
    private function cleanupRegistry(Project $project): void
    {
        try {
            $region = $project->knativeRegion;
            if ($region) {
                $this->registry->deleteAllForProject($region, $project);
            }
        } catch (\Throwable $e) {
            Log::warning('Registry image cleanup failed during project delete', [
                'project' => $project->slug,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
