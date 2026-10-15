<?php

namespace Database\Seeders;

use App\Models\ExecutorImage;
use Illuminate\Database\Seeder;

class MlRuntimeSeeder extends Seeder
{
    public function run(): void
    {
        $runtimes = [
            ['runtime' => 'tensorflow', 'version' => '2.16',     'docker_image' => 'ghcr.io/brf-tech/exec-tensorflow:2.16',     'is_default' => true,  'category' => 'ml', 'gpu_required' => false, 'sort_order' => 1],
            ['runtime' => 'tensorflow', 'version' => '2.16-gpu', 'docker_image' => 'ghcr.io/brf-tech/exec-tensorflow:2.16-gpu', 'is_default' => false, 'category' => 'ml', 'gpu_required' => true,  'sort_order' => 2],
            ['runtime' => 'tensorflow', 'version' => '2.15',     'docker_image' => 'ghcr.io/brf-tech/exec-tensorflow:2.15',     'is_default' => false, 'category' => 'ml', 'gpu_required' => false, 'sort_order' => 3],
            ['runtime' => 'tensorflow', 'version' => '2.15-gpu', 'docker_image' => 'ghcr.io/brf-tech/exec-tensorflow:2.15-gpu', 'is_default' => false, 'category' => 'ml', 'gpu_required' => true,  'sort_order' => 4],
            ['runtime' => 'pytorch',    'version' => '2.3',      'docker_image' => 'ghcr.io/brf-tech/exec-pytorch:2.3',         'is_default' => true,  'category' => 'ml', 'gpu_required' => false, 'sort_order' => 1],
            ['runtime' => 'pytorch',    'version' => '2.3-gpu',  'docker_image' => 'ghcr.io/brf-tech/exec-pytorch:2.3-gpu',     'is_default' => false, 'category' => 'ml', 'gpu_required' => true,  'sort_order' => 2],
            ['runtime' => 'pytorch',    'version' => '2.2',      'docker_image' => 'ghcr.io/brf-tech/exec-pytorch:2.2',         'is_default' => false, 'category' => 'ml', 'gpu_required' => false, 'sort_order' => 3],
            ['runtime' => 'pytorch',    'version' => '2.2-gpu',  'docker_image' => 'ghcr.io/brf-tech/exec-pytorch:2.2-gpu',     'is_default' => false, 'category' => 'ml', 'gpu_required' => true,  'sort_order' => 4],
        ];

        foreach ($runtimes as $rt) {
            ExecutorImage::firstOrCreate(
                ['runtime' => $rt['runtime'], 'version' => $rt['version']],
                array_merge($rt, ['is_active' => true]),
            );
        }
    }
}
