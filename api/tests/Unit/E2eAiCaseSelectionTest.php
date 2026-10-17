<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use PHPUnit\Framework\TestCase;

/**
 * The AI e2e runs single cases (--ai-cases / RUNR_E2E_AI_CASES, e.g. the GPU case on its own) with
 * what they need, and its GPU case stops at once with the harness's reason when the host GPU needs
 * a reset (--gpu-abort-file), instead of waiting 1200 s for an allocatable nvidia.com/gpu.
 */
class E2eAiCaseSelectionTest extends TestCase
{
    /** @return array{cases: ?list<string>, unknown: list<string>} */
    private function select(string $spec): array
    {
        return (new \ReflectionMethod(E2eTestCommand::class, 'aiSelectCases'))->invoke(null, $spec);
    }

    private function problem(string $path): ?string
    {
        return (new \ReflectionMethod(E2eTestCommand::class, 'aiHostGpuProblem'))->invoke(null, $path);
    }

    public function test_nothing_named_runs_every_case(): void
    {
        $this->assertSame(['cases' => null, 'unknown' => []], $this->select(''));
        $this->assertSame(['cases' => null, 'unknown' => []], $this->select(' , '));
    }

    public function test_the_gpu_case_runs_on_its_own(): void
    {
        $this->assertSame(['cases' => ['ai-gpu-caas'], 'unknown' => []], $this->select('ai-gpu-caas'));
        $this->assertSame(['cases' => ['ai-tei-cpu'], 'unknown' => []], $this->select('ai-tei-cpu'));
    }

    public function test_a_case_brings_what_it_needs_in_the_suite_order(): void
    {
        $this->assertSame(
            ['ai-model', 'ai-mirror', 'ai-offline-cpu', 'ai-binding'],
            $this->select('ai-binding')['cases'],
        );
        $this->assertSame(
            ['ai-model', 'ai-online-cpu', 'ai-gpu-caas'],
            $this->select('ai-gpu-caas, ai-online-cpu')['cases'],
            'comma and space separated, the suite order kept',
        );
    }

    public function test_unknown_names_are_reported(): void
    {
        $selection = $this->select('ai-gpu,ai-model ai-gpu');
        $this->assertSame(['ai-gpu'], $selection['unknown']);
        $this->assertSame(['ai-model'], $selection['cases']);
    }

    public function test_the_harness_reason_stops_the_gpu_case(): void
    {
        $dir = sys_get_temp_dir().'/runr-e2e-gpu-abort-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $file = "{$dir}/abort";
        try {
            $this->assertNull($this->problem($file), 'no file: the case goes on');
            $this->assertNull($this->problem(''), 'no path: the case goes on');

            file_put_contents($file, "  \n");
            $this->assertNull($this->problem($file), 'an empty file is no reason');

            file_put_contents($file, "host GPU needs a reset (Xid 119,154; nvidia-smi: [GPU requires reset])\n");
            $this->assertSame('host GPU needs a reset (Xid 119,154; nvidia-smi: [GPU requires reset])', $this->problem($file));
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }
}
