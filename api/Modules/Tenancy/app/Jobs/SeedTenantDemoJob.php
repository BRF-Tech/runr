<?php

namespace Modules\Tenancy\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\Deployment;
use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Services\ProjectService;
use Database\Seeders\Concerns\DemoVersionsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Tenancy\Models\Tenant;

class SeedTenantDemoJob implements ShouldQueue
{
    use BroadcastsJobCompletion, DemoVersionsTrait;
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

        // Demo region + projects live in tenant schema (tenant-owned)
        $this->tenant->run(function () {
            $existing = KnativeRegion::where('is_demo', true)
                ->where('is_synced', false)
                ->first();

            if ($existing && $existing->projects()->exists()) {
                return;
            }

            $region = $this->createDemoRegion();
            $this->seedDemoProjects($region);
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

    private function seedDemoProjects(KnativeRegion $region): void
    {
        $svc = app(ProjectService::class);
        $samples = $this->demoProjectSamples();

        $created = [];
        foreach ($samples as $data) {
            $created[] = $svc->create(array_merge($data, [
                'created_by' => $this->userId,
                'knative_region_id' => $region->id,
            ]));
        }

        foreach ($created as $project) {
            $project->load('latestVersion');
            if ($project->latestVersion) {
                Deployment::create([
                    'project_id' => $project->id,
                    'version_id' => $project->latestVersion->id,
                    'status' => 'active',
                    'deployed_by' => $this->userId,
                ]);
            }
        }

        foreach ($created as $project) {
            match ($project->service_type) {
                'xaas' => $this->seedXaaSVersions($svc, $project, $this->userId),
                'faas' => $this->seedFaaSVersions($svc, $project, $this->userId),
                'caas' => $this->seedCaaSVersions($svc, $project, $this->userId),
            };
        }
    }

    private function seedEolRuntimes(): void
    {
        ExecutorImage::firstOrCreate(
            ['runtime' => 'python', 'version' => '3.9'],
            ['docker_image' => 'ghcr.io/runrsh/exec-python:3.9', 'is_default' => false, 'is_active' => true, 'eol_at' => '2025-10-07', 'sort_order' => 90],
        );
        ExecutorImage::firstOrCreate(
            ['runtime' => 'nodejs', 'version' => '18'],
            ['docker_image' => 'ghcr.io/runrsh/exec-nodejs:18', 'is_default' => false, 'is_active' => true, 'eol_at' => now()->addWeeks(8)->toDateString(), 'sort_order' => 90],
        );
    }

    private function demoProjectSamples(): array
    {
        return [
            ['name' => 'demo-xaas-http',      'service_type' => 'xaas', 'runtime' => 'python', 'runtime_version' => '3.12', 'description' => 'Demo XaaS HTTP fonksiyon', 'config' => ['trigger_type' => 'http', 'memory_mb' => 256, 'timeout_seconds' => 30]],
            ['name' => 'demo-xaas-schedule',   'service_type' => 'xaas', 'runtime' => 'python', 'runtime_version' => '3.12', 'description' => 'Demo XaaS cron fonksiyon', 'config' => ['trigger_type' => 'schedule', 'schedule' => '0 * * * *', 'memory_mb' => 256, 'timeout_seconds' => 30]],
            ['name' => 'demo-faas-http',       'service_type' => 'faas', 'runtime' => 'nodejs', 'runtime_version' => '22', 'description' => 'Demo FaaS HTTP fonksiyon', 'config' => ['handler' => 'main.handler', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30]],
            ['name' => 'demo-faas-schedule',   'service_type' => 'faas', 'runtime' => 'nodejs', 'runtime_version' => '22', 'description' => 'Demo FaaS cron fonksiyon', 'config' => ['handler' => 'main.handler', 'trigger_type' => 'schedule', 'schedule' => '*/15 * * * *', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30]],
            ['name' => 'demo-caas-http',       'service_type' => 'caas', 'description' => 'Demo CaaS HTTP konteyner', 'config' => ['image' => 'ghcr.io/runrsh/reqs/nginx:1.27-alpine', 'trigger_type' => 'http', 'cpu' => '500m', 'memory_mb' => 256, 'timeout_seconds' => 30]],
            ['name' => 'demo-caas-schedule',   'service_type' => 'caas', 'description' => 'Demo CaaS cron konteyner', 'config' => ['image' => 'ghcr.io/runrsh/reqs/alpine:3.21', 'command' => ['echo', 'hello'], 'trigger_type' => 'schedule', 'schedule' => '0 0 * * *', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 60]],
            ['name' => 'demo-xaas-queue',      'service_type' => 'xaas', 'runtime' => 'python', 'runtime_version' => '3.12', 'description' => 'Demo XaaS event consumer', 'config' => ['trigger_type' => 'queue', 'queue_stream' => 'order-events', 'memory_mb' => 256, 'timeout_seconds' => 30]],
            ['name' => 'demo-faas-queue',      'service_type' => 'faas', 'runtime' => 'nodejs', 'runtime_version' => '20', 'description' => 'Demo FaaS event consumer', 'config' => ['handler' => 'main.handler', 'trigger_type' => 'queue', 'queue_stream' => 'payment-events', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30]],
            ['name' => 'demo-caas-queue',      'service_type' => 'caas', 'description' => 'Demo CaaS event consumer', 'config' => ['image' => 'ghcr.io/runrsh/reqs/nginx:1.27-alpine', 'trigger_type' => 'queue', 'queue_stream' => 'notification-events', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30]],
            ['name' => 'demo-xaas-eol',        'service_type' => 'xaas', 'runtime' => 'python', 'runtime_version' => '3.9', 'description' => 'Demo XaaS — EOL runtime', 'config' => ['trigger_type' => 'http', 'memory_mb' => 256, 'timeout_seconds' => 30]],
            ['name' => 'demo-faas-sunsetting', 'service_type' => 'faas', 'runtime' => 'nodejs', 'runtime_version' => '18', 'description' => 'Demo FaaS — sunsetting runtime', 'config' => ['handler' => 'main.handler', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30]],
        ];
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
