<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use Tests\TestCase;

/**
 * The ML cases of the main e2e (--ml, RUNR_E2E_ML=1; BRF-Tech/runr-runtimes' language bot runs them
 * on a candidate image): one HTTP function of each ML runtime with a default CPU version, given the
 * memory the framework needs, whose handler fails unless a tensor operation gives its result.
 */
class E2eMlCasesTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function cases(array $images): array
    {
        return E2eTestCommand::mlTestConfigs($images);
    }

    public function test_the_default_cpu_version_of_each_ml_runtime(): void
    {
        $cases = $this->cases([
            ['runtime' => 'pytorch', 'version' => '2.14', 'is_default' => true, 'gpu_required' => false, 'eol_at' => null],
            ['runtime' => 'pytorch', 'version' => '2.14-gpu', 'is_default' => false, 'gpu_required' => true, 'eol_at' => null],
            ['runtime' => 'pytorch', 'version' => '2.13', 'is_default' => false, 'gpu_required' => false, 'eol_at' => null],
            ['runtime' => 'tensorflow', 'version' => '2.21', 'is_default' => true, 'gpu_required' => false],
            ['runtime' => 'python', 'version' => '3.15', 'is_default' => true, 'gpu_required' => false, 'eol_at' => null],
        ]);

        $this->assertSame(['ml-pytorch-2.14-http', 'ml-tensorflow-2.21-http'], array_keys($cases));
        $pytorch = $cases['ml-pytorch-2.14-http'];
        $this->assertSame(['xaas', 'pytorch', '2.14', 'http'], [$pytorch['service_type'], $pytorch['runtime'], $pytorch['runtime_version'], $pytorch['trigger_type']]);
        $this->assertSame(['cpu' => '500m', 'memory_mb' => 1024], $pytorch['project_config'], 'the framework loads in 128 MiB no more');
        $this->assertStringContainsString('import torch', $pytorch['code']);
        $this->assertStringContainsString('def handler(event, context):', $pytorch['code']);
        $this->assertStringContainsString('raise RuntimeError', $pytorch['code'], 'a wrong result answers 500, which the curl check fails on');
        $this->assertStringContainsString('import tensorflow as tf', $cases['ml-tensorflow-2.21-http']['code']);
    }

    public function test_none_without_an_ml_runtime_or_past_its_end_of_life(): void
    {
        $this->assertSame([], $this->cases([['runtime' => 'python', 'version' => '3.15', 'is_default' => true]]));
        $this->assertSame([], $this->cases([['runtime' => 'pytorch', 'version' => '2.10', 'is_default' => true, 'eol_at' => '2020-01-01']]));
        $this->assertSame([], $this->cases([['runtime' => 'pytorch', 'version' => '2.14-gpu', 'is_default' => true, 'gpu_required' => true]]));
    }

    public function test_the_option_is_offered(): void
    {
        $this->assertTrue($this->app->make(E2eTestCommand::class)->getDefinition()->hasOption('ml'));
    }
}
