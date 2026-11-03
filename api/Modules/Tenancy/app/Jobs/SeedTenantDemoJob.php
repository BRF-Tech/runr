<?php

namespace Modules\Tenancy\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\User;
use Database\Seeders\Concerns\DemoProjectsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Tenancy\Models\Tenant;

class SeedTenantDemoJob implements ShouldQueue
{
    use BroadcastsJobCompletion, DemoProjectsTrait;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public Tenant $tenant, public string $userId)
    {
        $this->initiatorId = $userId;
    }

    public function handle(): void
    {
        $this->seedEolRuntimes();

        // The deployments name their deployer by user name, as a deploy does
        $username = User::query()->whereKey($this->userId)->value('username') ?? 'admin';

        // Demo region + projects live in tenant schema (tenant-owned)
        $this->tenant->run(function () use ($username) {
            $existing = KnativeRegion::where('is_demo', true)
                ->where('is_synced', false)
                ->first();

            if ($existing && $existing->projects()->exists()) {
                return;
            }

            $region = $this->createDemoRegion();
            $this->seedDemoProjectSet($region, $this->userId, $username);
        });

        $this->tenant->update(['data' => array_merge($this->tenant->data ?? [], ['demo_status' => 'idle'])]);

        $this->broadcastSuccess('demo.seeded', [
            'tenant_id' => $this->tenant->id,
        ], "Demo data seeded for {$this->tenant->slug}");
    }

    private function createDemoRegion(): KnativeRegion
    {
        return KnativeRegion::firstOrCreate(
            ['name' => "demo-{$this->tenant->slug}"],
            [
                'kubeconfig' => 'demo',
                'total_cpu_m' => 8000,
                'total_memory_mb' => 16384,
                'is_active' => true,
                'is_demo' => true,
                'is_synced' => false,
                'owner_tenant_id' => $this->tenant->id,
                'knative_status' => 'ready',
                'knative_status_message' => 'Demo region',
                'knative_status_checked_at' => now(),
                'external_ip' => '203.0.113.42',
                'selected_workers' => ['demo-worker-1'],
                'project_namespace' => "demo-{$this->tenant->slug}",
                'knative_operator_status' => 'ready',
                'knative_operator_status_message' => 'Knative Operator is ready.',
                'knative_operator_namespace' => 'knative-operator',
                'knative_serving_status' => 'ready',
                'knative_serving_status_message' => 'Knative Serving is ready.',
                'knative_serving_namespace' => 'knative-serving',
                'knative_serving_ingress_class' => 'traefik',
                'redis_mode' => 'managed',
                'redis_address' => 'redis://runr-redis-master.runr-system:6379',
                'knative_redis_status' => 'ready',
                'knative_redis_status_message' => 'Redis configured successfully.',
                'registry_mode' => 'internal',
                'registry_address' => '10.96.100.50:5000',
                'registry_storage_type' => 'pvc',
                'registry_storage_config' => ['storage_class' => 'standard', 'size' => '10Gi'],
                'registry_status' => 'ready',
                'registry_status_message' => 'Zot registry is running.',
                'registry_namespace' => 'runr-registry',
                'knative_settings' => [
                    'namespace' => 'runr-projects',
                    'knative_domain' => "demo-{$this->tenant->slug}.runr.sh",
                    'request_timeout' => 300,
                ],
            ]
        );
    }

    private function seedEolRuntimes(): void
    {
        ExecutorImage::firstOrCreate(
            ['runtime' => 'python', 'version' => '3.9'],
            ['docker_image' => 'ghcr.io/brf-tech/runr/exec-python:3.9', 'is_default' => false, 'is_active' => true, 'eol_at' => '2025-10-07', 'sort_order' => 90],
        );
        ExecutorImage::firstOrCreate(
            ['runtime' => 'nodejs', 'version' => '18'],
            ['docker_image' => 'ghcr.io/brf-tech/runr/exec-nodejs:18', 'is_default' => false, 'is_active' => true, 'eol_at' => now()->addWeeks(8)->toDateString(), 'sort_order' => 90],
        );
    }

    public function failed(?\Throwable $e): void
    {
        $this->tenant->update(['data' => array_merge($this->tenant->data ?? [], ['demo_status' => 'error'])]);
        \Log::error("SeedTenantDemoJob failed for {$this->tenant->slug}: ".$e?->getMessage());

        if ($e) {
            $this->broadcastFailure('demo.seed_failed', [
                'tenant_id' => $this->tenant->id,
            ], $e, "Demo seeding failed for {$this->tenant->slug}");
        }
    }
}
