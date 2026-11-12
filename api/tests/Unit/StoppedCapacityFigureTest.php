<?php

namespace Tests\Unit;

use App\Models\KnativeRegion;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\ResourceReservation;
use Tests\TestCase;

/**
 * The region with its stopped projects back (RegionCapacity::withStopped(), the capacity endpoint's
 * with_stopped and the capacity alarm): what each budget would hold, how full the fuller one would
 * be, by how much it would be exceeded, and how much capacity keeps it under an alarm level.
 */
class StoppedCapacityFigureTest extends TestCase
{
    private function region(int $cpuM, int $memoryMb, float $cpuFactor = 3, float $memoryFactor = 1.5): KnativeRegion
    {
        return (new KnativeRegion)->forceFill([
            'total_cpu_m' => $cpuM, 'total_memory_mb' => $memoryMb,
            'faas_cpu_overcommit' => $cpuFactor, 'faas_memory_overcommit' => $memoryFactor,
        ]);
    }

    /** @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}} */
    private function demand(int $hardCpu, int $burstCpu, int $hardMemory = 0, int $burstMemory = 0): array
    {
        return ['hard' => ['cpu_m' => $hardCpu, 'memory_mb' => $hardMemory], 'burst' => ['cpu_m' => $burstCpu, 'memory_mb' => $burstMemory]];
    }

    public function test_the_figure_adds_the_stopped_projects_to_both_budgets(): void
    {
        // 1000m × 3: 300m always-on and 1200m FaaS held now; the stopped projects add 400m always-on and 1500m FaaS
        $figure = RegionCapacity::withStopped($this->region(1000, 4096), $this->demand(300, 1200), [
            'demand' => $this->demand(400, 1500),
            'projects' => [['id' => 'a'], ['id' => 'b']],
        ]);

        $this->assertSame(2, $figure['projects']);
        $cpu = $figure['cpu_m'];
        $this->assertSame([400, 1500, 700, 2700], [$cpu['stopped_always_on'], $cpu['stopped_faas'], $cpu['always_on_used'], $cpu['faas_used']]);
        // always-on 700 / 1000 = 70 %; all of it 3400 / 3000 = 113.3 %: the FaaS budget is fuller, 400m short
        $this->assertEquals(113.3, $cpu['utilization']);
        $this->assertSame(RegionCapacity::FAAS, $cpu['budget']);
        $this->assertSame(400, $cpu['exceeded_by']);

        // nothing stopped of the memory: as now, within the budgets
        $this->assertSame(0, $figure['memory_mb']['exceeded_by']);
        $this->assertEquals(0.0, $figure['memory_mb']['utilization']);
    }

    public function test_the_always_on_budget_can_be_the_one_exceeded(): void
    {
        $figure = RegionCapacity::withStopped($this->region(1000, 4096), $this->demand(800, 0), [
            'demand' => $this->demand(500, 0),
            'projects' => [['id' => 'a']],
        ]);

        $this->assertSame(RegionCapacity::ALWAYS_ON, $figure['cpu_m']['budget']);
        $this->assertSame(300, $figure['cpu_m']['exceeded_by']);
        $this->assertEquals(130.0, $figure['cpu_m']['utilization']);
    }

    public function test_a_region_without_a_measured_capacity_has_no_figure(): void
    {
        $figure = RegionCapacity::withStopped($this->region(0, 0), ResourceReservation::noDemand(), [
            'demand' => $this->demand(500, 500),
            'projects' => [['id' => 'a']],
        ]);

        $this->assertNull($figure['cpu_m']['utilization']);
        $this->assertNull($figure['cpu_m']['budget']);
        $this->assertSame(0, $figure['cpu_m']['exceeded_by']);
    }

    public function test_the_capacity_to_add_brings_the_fuller_budget_under_the_level(): void
    {
        // 3150m on the FaaS budget of 1000m × 3: under 80 % takes 3150 / 2.4 = 1312.5 → 1313m, 313m more
        $this->assertSame(313, RegionCapacity::capacityToAdd(1000, 3, 0, 3150, 80));
        // always-on 900m of 1000m: under 60 % takes 1500m
        $this->assertSame(500, RegionCapacity::capacityToAdd(1000, 3, 900, 0, 60));
        // already under the level, or nothing measured: nothing to add
        $this->assertSame(0, RegionCapacity::capacityToAdd(1000, 3, 100, 200, 80));
        $this->assertSame(0, RegionCapacity::capacityToAdd(0, 3, 900, 900, 80));
    }
}
