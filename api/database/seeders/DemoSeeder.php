<?php

namespace Database\Seeders;

use App\Models\Deployment;
use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\ProjectService;
use Database\Seeders\Concerns\DemoVersionsTrait;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    use DemoVersionsTrait;

    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            SettingsSeeder::class,
        ]);

        $this->seedEolRuntimes();
        $this->seedMlRuntimes();
        $region = $this->seedDemoRegion();
        $this->seedDemoProjects($region);
    }

    private function seedEolRuntimes(): void
    {
        // Runtime past its EOL (red badge)
        ExecutorImage::firstOrCreate(
            ['runtime' => 'python', 'version' => '3.9'],
            ['docker_image' => 'ghcr.io/runrsh/exec-python:3.9', 'is_default' => false, 'is_active' => true, 'eol_at' => '2025-10-07', 'sort_order' => 90],
        );
        // Runtime nearing its EOL — sunsetting (yellow badge)
        ExecutorImage::firstOrCreate(
            ['runtime' => 'nodejs', 'version' => '18'],
            ['docker_image' => 'ghcr.io/runrsh/exec-nodejs:18', 'is_default' => false, 'is_active' => true, 'eol_at' => now()->addWeeks(8)->toDateString(), 'sort_order' => 90],
        );
    }

    private function seedMlRuntimes(): void
    {
        $mlRuntimes = [
            ['runtime' => 'tensorflow', 'version' => '2.16',     'docker_image' => 'ghcr.io/runrsh/exec-tensorflow:2.16',     'is_default' => true,  'category' => 'ml', 'gpu_required' => false, 'sort_order' => 1],
            ['runtime' => 'tensorflow', 'version' => '2.16-gpu', 'docker_image' => 'ghcr.io/runrsh/exec-tensorflow:2.16-gpu', 'is_default' => false, 'category' => 'ml', 'gpu_required' => true,  'sort_order' => 2],
            ['runtime' => 'tensorflow', 'version' => '2.15',     'docker_image' => 'ghcr.io/runrsh/exec-tensorflow:2.15',     'is_default' => false, 'category' => 'ml', 'gpu_required' => false, 'sort_order' => 3],
            ['runtime' => 'tensorflow', 'version' => '2.15-gpu', 'docker_image' => 'ghcr.io/runrsh/exec-tensorflow:2.15-gpu', 'is_default' => false, 'category' => 'ml', 'gpu_required' => true,  'sort_order' => 4],
            ['runtime' => 'pytorch',    'version' => '2.3',      'docker_image' => 'ghcr.io/runrsh/exec-pytorch:2.3',         'is_default' => true,  'category' => 'ml', 'gpu_required' => false, 'sort_order' => 1],
            ['runtime' => 'pytorch',    'version' => '2.3-gpu',  'docker_image' => 'ghcr.io/runrsh/exec-pytorch:2.3-gpu',     'is_default' => false, 'category' => 'ml', 'gpu_required' => true,  'sort_order' => 2],
            ['runtime' => 'pytorch',    'version' => '2.2',      'docker_image' => 'ghcr.io/runrsh/exec-pytorch:2.2',         'is_default' => false, 'category' => 'ml', 'gpu_required' => false, 'sort_order' => 3],
            ['runtime' => 'pytorch',    'version' => '2.2-gpu',  'docker_image' => 'ghcr.io/runrsh/exec-pytorch:2.2-gpu',     'is_default' => false, 'category' => 'ml', 'gpu_required' => true,  'sort_order' => 4],
        ];

        foreach ($mlRuntimes as $ml) {
            ExecutorImage::firstOrCreate(
                ['runtime' => $ml['runtime'], 'version' => $ml['version']],
                array_merge($ml, ['is_active' => true]),
            );
        }
    }

    private function seedDemoRegion(): KnativeRegion
    {
        return KnativeRegion::firstOrCreate(
            ['name' => 'demo-region'],
            [
                'kubeconfig' => 'demo',
                'total_cpu_m' => 8000,
                'total_memory_mb' => 16384,
                'is_active' => true,
                'is_demo' => true,
                'knative_status' => 'ready',
                'knative_status_message' => 'Demo region',
                'knative_status_checked_at' => now(),
                'external_ip' => '203.0.113.42',
                'selected_workers' => ['demo-worker-3'],
                'project_namespace' => 'demo-region',
                // Component statuses
                'knative_operator_status' => 'ready',
                'knative_operator_status_message' => 'Knative Operator is ready.',
                'knative_operator_namespace' => 'knative-operator',
                'knative_serving_status' => 'ready',
                'knative_serving_status_message' => 'Knative Serving is ready.',
                'knative_serving_namespace' => 'knative-serving',
                'knative_serving_ingress_class' => 'traefik',
                // Redis
                'redis_mode' => 'managed',
                'redis_address' => 'redis://runr-redis-master.runr-system:6379',
                'knative_redis_status' => 'ready',
                'knative_redis_status_message' => 'Redis configured successfully.',
                // Registry
                'registry_mode' => 'internal',
                'registry_address' => '10.96.100.50:5000',
                'registry_storage_type' => 'pvc',
                'registry_storage_config' => [
                    'storage_class' => 'standard',
                    'size' => '10Gi',
                ],
                'registry_status' => 'ready',
                'registry_status_message' => 'Zot registry is running.',
                'registry_namespace' => 'runr-registry',
                'knative_settings' => [
                    'namespace' => 'runr-projects',
                    'knative_domain' => 'demo-region.runr.sh',
                    'request_timeout' => 300,
                ],
            ]
        );
    }

    private function seedDemoProjects(KnativeRegion $region): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $svc = app(ProjectService::class);
        $samples = [
            [
                'name' => 'demo-xaas-http',
                'service_type' => 'xaas',
                'runtime' => 'python',
                'runtime_version' => '3.12',
                'description' => 'Demo XaaS HTTP fonksiyon',
                'config' => ['trigger_type' => 'http', 'memory_mb' => 256, 'timeout_seconds' => 30],
            ],
            [
                'name' => 'demo-xaas-schedule',
                'service_type' => 'xaas',
                'runtime' => 'python',
                'runtime_version' => '3.12',
                'description' => 'Demo XaaS cron fonksiyon',
                'config' => ['trigger_type' => 'schedule', 'schedule' => '0 * * * *', 'memory_mb' => 256, 'timeout_seconds' => 30],
            ],
            [
                'name' => 'demo-faas-http',
                'service_type' => 'faas',
                'runtime' => 'nodejs',
                'runtime_version' => '22',
                'description' => 'Demo FaaS HTTP fonksiyon',
                'config' => ['handler' => 'main.handler', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30],
            ],
            [
                'name' => 'demo-faas-schedule',
                'service_type' => 'faas',
                'runtime' => 'nodejs',
                'runtime_version' => '22',
                'description' => 'Demo FaaS cron fonksiyon',
                'config' => ['handler' => 'main.handler', 'trigger_type' => 'schedule', 'schedule' => '*/15 * * * *', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30],
            ],
            [
                'name' => 'demo-caas-http',
                'service_type' => 'caas',
                'description' => 'Demo CaaS HTTP konteyner',
                'config' => ['image' => 'ghcr.io/runrsh/reqs/nginx:1.27-alpine', 'trigger_type' => 'http', 'cpu' => '500m', 'memory_mb' => 256, 'timeout_seconds' => 30],
            ],
            [
                'name' => 'demo-caas-schedule',
                'service_type' => 'caas',
                'description' => 'Demo CaaS cron konteyner',
                'config' => ['image' => 'ghcr.io/runrsh/reqs/alpine:3.21', 'command' => ['echo', 'hello'], 'trigger_type' => 'schedule', 'schedule' => '0 0 * * *', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 60],
            ],
            [
                'name' => 'demo-xaas-queue',
                'service_type' => 'xaas',
                'runtime' => 'python',
                'runtime_version' => '3.12',
                'description' => 'Demo XaaS event consumer',
                'config' => ['trigger_type' => 'queue', 'queue_stream' => 'order-events', 'memory_mb' => 256, 'timeout_seconds' => 30],
            ],
            [
                'name' => 'demo-faas-queue',
                'service_type' => 'faas',
                'runtime' => 'nodejs',
                'runtime_version' => '20',
                'description' => 'Demo FaaS event consumer',
                'config' => ['handler' => 'main.handler', 'trigger_type' => 'queue', 'queue_stream' => 'payment-events', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30],
            ],
            [
                'name' => 'demo-caas-queue',
                'service_type' => 'caas',
                'description' => 'Demo CaaS event consumer',
                'config' => ['image' => 'ghcr.io/runrsh/reqs/nginx:1.27-alpine', 'trigger_type' => 'queue', 'queue_stream' => 'notification-events', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30],
            ],
            // Project past its EOL (red card)
            [
                'name' => 'demo-xaas-eol',
                'service_type' => 'xaas',
                'runtime' => 'python',
                'runtime_version' => '3.9',
                'description' => 'Demo XaaS — EOL runtime',
                'config' => ['trigger_type' => 'http', 'memory_mb' => 256, 'timeout_seconds' => 30],
            ],
            // Project nearing its EOL (yellow card)
            [
                'name' => 'demo-faas-sunsetting',
                'service_type' => 'faas',
                'runtime' => 'nodejs',
                'runtime_version' => '18',
                'description' => 'Demo FaaS — sunsetting runtime',
                'config' => ['handler' => 'main.handler', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30],
            ],
        ];

        $created = [];
        foreach ($samples as $data) {
            $created[] = $svc->create(array_merge($data, [
                'created_by' => $admin->id,
                'knative_region_id' => $region->id,
            ]));
        }

        // Add an active v1 deployment to every project
        foreach ($created as $project) {
            $project->load('latestVersion');
            if ($project->latestVersion) {
                Deployment::create([
                    'project_id' => $project->id,
                    'version_id' => $project->latestVersion->id,
                    'status' => 'active',
                    'deployed_by' => $admin->id,
                ]);
            }
        }

        // Incremental versions
        foreach ($created as $project) {
            match ($project->service_type) {
                'xaas' => $this->seedXaaSVersions($svc, $project, $admin->id),
                'faas' => $this->seedFaaSVersions($svc, $project, $admin->id),
                'caas' => $this->seedCaaSVersions($svc, $project, $admin->id),
            };
        }
    }
}
