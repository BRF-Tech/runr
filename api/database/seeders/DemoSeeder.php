<?php

namespace Database\Seeders;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\User;
use Database\Seeders\Concerns\DemoProjectsTrait;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    use DemoProjectsTrait;

    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            SettingsSeeder::class,
            MlRuntimeSeeder::class,
        ]);

        $this->seedEolRuntimes();
        $region = $this->seedDemoRegion();
        $this->seedDemoProjects($region);
    }

    private function seedEolRuntimes(): void
    {
        // Runtime past its EOL (red badge)
        ExecutorImage::firstOrCreate(
            ['runtime' => 'python', 'version' => '3.9'],
            ['docker_image' => 'ghcr.io/brf-tech/runr/exec-python:3.9', 'is_default' => false, 'is_active' => true, 'eol_at' => '2025-10-07', 'sort_order' => 90],
        );
        // Runtime nearing its EOL — sunsetting (yellow badge)
        ExecutorImage::firstOrCreate(
            ['runtime' => 'nodejs', 'version' => '18'],
            ['docker_image' => 'ghcr.io/brf-tech/runr/exec-nodejs:18', 'is_default' => false, 'is_active' => true, 'eol_at' => now()->addWeeks(8)->toDateString(), 'sort_order' => 90],
        );
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

    /**
     * The demo projects (DemoProjectsTrait): versions and a deployment history each. A second run
     * (runr:install --demo again) keeps the projects the region has.
     */
    private function seedDemoProjects(KnativeRegion $region): void
    {
        if ($region->projects()->exists()) {
            return;
        }

        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedDemoProjectSet($region, $admin->id, $admin->username);
    }
}
