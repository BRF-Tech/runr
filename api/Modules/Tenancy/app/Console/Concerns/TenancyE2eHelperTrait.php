<?php

namespace Modules\Tenancy\Console\Concerns;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

trait TenancyE2eHelperTrait
{
    protected function api(?string $token = null, ?string $tenantId = null): PendingRequest
    {
        $req = Http::withToken($token ?? $this->adminToken)
            ->accept('application/json')
            ->baseUrl($this->baseUrl);

        if ($tenantId) {
            $req = $req->withHeaders(['X-Tenant-ID' => $tenantId]);
        }

        return $req;
    }

    protected function publicApi(): PendingRequest
    {
        return Http::accept('application/json')->baseUrl($this->baseUrl);
    }

    protected function runTest(string $label, callable $fn): array
    {
        $start = microtime(true);
        try {
            $fn();

            return [
                'label' => $label, 'passed' => true,
                'message' => '', 'time' => microtime(true) - $start,
            ];
        } catch (\Throwable $e) {
            return [
                'label' => $label, 'passed' => false,
                'message' => $e->getMessage(), 'time' => microtime(true) - $start,
            ];
        }
    }

    protected function e2eAssert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Deletion runs in DeleteTenantJob (202): waits until none of the tenants is still deleting
     * (gone, or kept as delete_failed) and returns the tenant list of that moment.
     *
     * @param  list<string>  $ids
     */
    protected function waitForTenantDeletions(array $ids): Collection
    {
        $deadline = time() + 180;
        do {
            $tenants = collect($this->api()->get('/api/tenants')->json());
            if ($tenants->whereIn('id', $ids)->where('status', 'deleting')->isEmpty()) {
                break;
            }
            sleep(3);
        } while (time() < $deadline);

        return $tenants;
    }

    /** The create payload of a small snippet project in the managed test region. */
    protected function snippetProject(string $name): array
    {
        return [
            'name' => $name,
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => '3.12',
            'knative_region_id' => $this->managedRegionId,
            'config' => ['timeout_seconds' => 30, 'cpu' => '250m', 'memory_mb' => 128],
        ];
    }

    /** Create a region directly in central DB with a dummy kubeconfig. */
    protected function seedTestRegion(string $name, string $mode, int $cpu, int $mem): string
    {
        $region = KnativeRegion::create([
            'name' => $name,
            'is_active' => true,
            'region_mode' => $mode,
            'total_cpu_m' => $cpu,
            'total_memory_mb' => $mem,
            'cluster_status' => 'online',
            'kubeconfig' => 'e2e-dummy-kubeconfig',
        ]);

        return $region->id;
    }

    /** Seed a minimal executor image for project creation tests. */
    protected function seedExecutorImage(): void
    {
        ExecutorImage::firstOrCreate(
            ['runtime' => 'python', 'version' => '3.12'],
            [
                'docker_image' => 'ghcr.io/brf-tech/python:3.12',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );
    }
}
