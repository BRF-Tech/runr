<?php

namespace Database\Seeders;

use App\Models\ExecutorImage;
use Illuminate\Database\Seeder;

/**
 * The ML runtime versions RunR publishes (`ci.sh ml-runtimes --list` of BRF-Tech/runr-runtimes),
 * for local and demo installs that do not run executor:sync against the image registry.
 */
class MlRuntimeSeeder extends Seeder
{
    public function run(): void
    {
        $runtimes = [
            ['runtime' => 'tensorflow', 'version' => '2.21',     'docker_image' => 'ghcr.io/brf-tech/runr/exec-tensorflow:2.21',     'is_default' => true,  'category' => 'ml', 'gpu_required' => false, 'sort_order' => 1],
            ['runtime' => 'tensorflow', 'version' => '2.21-gpu', 'docker_image' => 'ghcr.io/brf-tech/runr/exec-tensorflow:2.21-gpu', 'is_default' => false, 'category' => 'ml', 'gpu_required' => true,  'sort_order' => 2],
            ['runtime' => 'pytorch',    'version' => '2.14',     'docker_image' => 'ghcr.io/brf-tech/runr/exec-pytorch:2.14',        'is_default' => true,  'category' => 'ml', 'gpu_required' => false, 'sort_order' => 1],
            ['runtime' => 'pytorch',    'version' => '2.14-gpu', 'docker_image' => 'ghcr.io/brf-tech/runr/exec-pytorch:2.14-gpu',    'is_default' => false, 'category' => 'ml', 'gpu_required' => true,  'sort_order' => 2],
        ];

        foreach ($runtimes as $rt) {
            ExecutorImage::firstOrCreate(
                ['runtime' => $rt['runtime'], 'version' => $rt['version']],
                array_merge($rt, ['is_active' => true]),
            );
        }
    }
}
