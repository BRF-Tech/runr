<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use Tests\TestCase;

/**
 * The main e2e runs its deploy cases in waves the region runs at once (E2eTestWavesTrait,
 * docs/development.md → E2E tests): a wave takes the next cases while one pod of each — the size
 * admission counts, plus the e2e's curl / XADD pod where it shares the project nodes — fits what
 * the region has free; its projects are deleted once it is verified.
 */
class E2eWavesTest extends TestCase
{
    private const RUNTIMES = ['bun', 'dotnet', 'go', 'java', 'nodejs', 'php', 'python', 'ruby'];

    private function command(): E2eTestCommand
    {
        return $this->app->make(E2eTestCommand::class);
    }

    /** The project config the e2e creates a case with (buildProjectPayload). */
    private function payloadConfig(string $name, array $case): array
    {
        $command = $this->command();

        return (fn () => $this->buildProjectPayload($name, $case, 'region-1'))->call($command)['config'];
    }

    private function demand(array $case, bool $sharesNodes = false): array
    {
        return E2eTestCommand::e2eCaseDemand($case['service_type'], $this->payloadConfig('case', $case), null, $sharesNodes);
    }

    /** The 33 cases of the main matrix of 0.6.8: 8 runtimes × http / schedule / queue, 8 FaaS, nginx. */
    private function mainMatrix(): array
    {
        $cases = [];
        foreach (self::RUNTIMES as $runtime) {
            foreach (['http', 'schedule', 'queue'] as $trigger) {
                $cases[] = ['service_type' => 'xaas', 'runtime' => $runtime, 'trigger_type' => $trigger];
            }
        }
        foreach (self::RUNTIMES as $runtime) {
            $cases[] = ['service_type' => 'faas', 'runtime' => $runtime, 'trigger_type' => 'http'];
        }
        $cases[] = ['service_type' => 'caas', 'trigger_type' => 'http', 'image' => 'nginx'];

        return $cases;
    }

    public function test_a_case_asks_for_the_pod_admission_counts(): void
    {
        // 250m / 128 MiB + the queue-proxy (100m / 100 MiB); a CronJob has none; a queue case adds its consumer
        $this->assertSame(['cpu_m' => 350, 'memory_mb' => 228], $this->demand(['service_type' => 'xaas', 'trigger_type' => 'http']));
        $this->assertSame(['cpu_m' => 250, 'memory_mb' => 128], $this->demand(['service_type' => 'xaas', 'trigger_type' => 'schedule']));
        $this->assertSame(['cpu_m' => 400, 'memory_mb' => 292], $this->demand(['service_type' => 'xaas', 'trigger_type' => 'queue']));
        $this->assertSame(['cpu_m' => 600, 'memory_mb' => 1124], $this->demand(['service_type' => 'xaas', 'trigger_type' => 'http', 'project_config' => ['cpu' => '500m', 'memory_mb' => 1024]]));
    }

    public function test_the_e2e_pod_counts_only_where_it_shares_the_project_nodes(): void
    {
        // dedicated workers are tainted for projects: curl and XADD run elsewhere
        $this->assertSame(['cpu_m' => 400, 'memory_mb' => 260], $this->demand(['service_type' => 'xaas', 'trigger_type' => 'http'], true));
        $this->assertSame(['cpu_m' => 450, 'memory_mb' => 324], $this->demand(['service_type' => 'xaas', 'trigger_type' => 'queue'], true));
        $this->assertSame(['cpu_m' => 250, 'memory_mb' => 128], $this->demand(['service_type' => 'xaas', 'trigger_type' => 'schedule'], true), 'the Job runs the function pod itself');
    }

    public function test_a_wave_takes_the_next_cases_in_order_while_they_fit(): void
    {
        $d = fn (int $cpu, int $memory = 100) => ['cpu_m' => $cpu, 'memory_mb' => $memory];

        $this->assertSame(3, E2eTestCommand::e2eWaveSize([$d(350), $d(250), $d(400), $d(350)], ['cpu_m' => 1000, 'memory_mb' => 1000]));
        $this->assertSame(3, E2eTestCommand::e2eWaveSize([$d(350), $d(250), $d(400)], ['cpu_m' => 1000, 'memory_mb' => 1000]), 'exactly the room still fits');
        // in order: a smaller case further on does not jump the queue
        $this->assertSame(1, E2eTestCommand::e2eWaveSize([$d(600), $d(600), $d(100)], ['cpu_m' => 1000, 'memory_mb' => 1000]));
        // memory limits too
        $this->assertSame(2, E2eTestCommand::e2eWaveSize([$d(100, 400), $d(100, 400), $d(100, 400)], ['cpu_m' => 1000, 'memory_mb' => 1000]));
    }

    public function test_the_room_keeps_what_the_stopped_projects_would_hold_again(): void
    {
        // a stopped project holds nothing of a pay-as-you-go region, but one the run resumes needs its room
        $data = [
            'cpu_m' => ['capacity' => 2860, 'always_on_used' => 0, 'faas_used' => 700],
            'memory_mb' => ['capacity' => 0, 'always_on_used' => 0, 'faas_used' => 456],
            'with_stopped' => [
                'cpu_m' => ['always_on_used' => 0, 'faas_used' => 1050],
                'memory_mb' => ['always_on_used' => 0, 'faas_used' => 684],
            ],
        ];
        $this->assertSame(['cpu_m' => 1810, 'memory_mb' => null], E2eTestCommand::e2eRoomOf($data));

        // an answer without the figure (an older API): what holds the region now
        unset($data['with_stopped']);
        $this->assertSame(['cpu_m' => 2160, 'memory_mb' => null], E2eTestCommand::e2eRoomOf($data));
    }

    public function test_a_case_larger_than_the_room_gets_a_wave_of_its_own(): void
    {
        // admission answers for it; the run goes on with the next wave
        $this->assertSame(1, E2eTestCommand::e2eWaveSize([['cpu_m' => 3000, 'memory_mb' => 100], ['cpu_m' => 100, 'memory_mb' => 100]], ['cpu_m' => 2860, 'memory_mb' => 10000]));
        $this->assertSame(1, E2eTestCommand::e2eWaveSize([['cpu_m' => 350, 'memory_mb' => 228]], ['cpu_m' => 0, 'memory_mb' => 0]));
    }

    public function test_a_capacity_not_measured_does_not_limit(): void
    {
        $demands = array_fill(0, 40, ['cpu_m' => 350, 'memory_mb' => 228]);

        $this->assertSame(40, E2eTestCommand::e2eWaveSize($demands, ['cpu_m' => null, 'memory_mb' => null]));
        $this->assertSame(8, E2eTestCommand::e2eWaveSize($demands, ['cpu_m' => 2860, 'memory_mb' => null]));
    }

    /**
     * The main matrix on a GitHub-hosted runner (4 vCPU): the dedicated Kind worker leaves 2860m
     * for projects. Kept all at once, the 33 cases would need 10750m of FaaS budget (2860m × 3 −
     * 400m always-on = 8180m: the 26th create was refused). In waves, nothing ever holds more than
     * the worker runs at once; the first ready HTTP function (350m) stays for the shell tests.
     */
    public function test_the_main_matrix_fits_a_four_vcpu_worker_in_waves(): void
    {
        $demands = array_map(fn (array $case) => $this->demand($case), $this->mainMatrix());
        $this->assertSame(10750 + 400, array_sum(array_column($demands, 'cpu_m')), 'one pod of each case plus 8 queue consumers');

        $capacity = 2860;
        $held = 0;
        $sizes = [];
        $start = 0;
        while ($start < count($demands)) {
            $room = ['cpu_m' => $capacity - $held, 'memory_mb' => 12000];
            $size = E2eTestCommand::e2eWaveSize(array_slice($demands, $start), $room);
            $wave = array_sum(array_column(array_slice($demands, $start, $size), 'cpu_m'));
            $this->assertLessThanOrEqual($capacity, $held + $wave, 'a wave and what stays never exceed the worker');
            $sizes[] = $size;
            $start += $size;
            $held = 350; // the shell tests' function, from the first wave on
        }

        $this->assertSame([8, 7, 7, 7, 4], $sizes);
    }

    public function test_the_placement_check_covers_project_pods_only(): void
    {
        $pod = fn (string $name, array $extra = [], string $phase = 'Running') => array_replace_recursive([
            'metadata' => ['name' => $name, 'labels' => []],
            'spec' => ['nodeName' => 'worker2'],
            'status' => ['phase' => $phase],
        ], $extra);

        $running = E2eTestCommand::filterRunningPods([
            $pod('fn-00001-deployment-a', ['metadata' => ['labels' => ['runr/project-id' => 'p1']]]),
            $pod('fn-qc-b'),
            $pod('runr-redis-0'),
            $pod('e2e-curl-1', ['metadata' => ['labels' => ['runr.dev/purpose' => 'e2e-curl']]]),
            $pod('old-00001-deployment-c', ['metadata' => ['deletionTimestamp' => '2026-11-11T17:20:00Z']]),
            $pod('job-x', [], 'Succeeded'),
        ]);

        $this->assertSame([
            ['name' => 'fn-00001-deployment-a', 'node' => 'worker2'],
            ['name' => 'fn-qc-b', 'node' => 'worker2'],
        ], $running);
    }
}
