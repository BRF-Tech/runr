<?php

namespace App\Console\Commands\Concerns;

use App\Models\KnativeRegion;
use App\Services\Helpers\ResourceReservation;
use App\Services\KnativeNodeService;

/**
 * The deploy cases of the main e2e run in waves the region runs at once (docs/development.md →
 * E2E tests): a wave is built and deployed case by case, verified, and its projects are deleted
 * before the next wave starts.
 *
 * Every project holds its reservation until it is deleted (docs/architecture.md → Region
 * capacity and admission): one pod of each scale-to-zero project against the FaaS overcommit
 * budget, whether it runs or not — except while it is stopped in a pay-as-you-go region, where a
 * stopped project holds nothing until a deploy admits it again. A suite that keeps every case's project needs a budget of the
 * sum of all cases: on a 4-vCPU Kind worker (2860m capacity × 3) the 33 cases of the main matrix
 * alone need 10750m, so from the 26th case on every create was refused, and the sandbox, runtime
 * and migration cases after them too. Overcommit is also no promise that everything runs at once:
 * the verify phase wakes every function of a run together, and pods beyond the node's capacity
 * wait as Pending (curl timeouts, a shell that found no running pod).
 *
 * A wave therefore takes the next cases while what they ask of the nodes at the same time — one
 * pod each, the size admission counts (ResourceReservation), plus the e2e's own curl / XADD pod
 * when it shares the project nodes — fits the room the region has: its capacity, measured again
 * before each wave, minus what the projects still there hold and what its stopped projects would
 * hold again (the capacity endpoint's with_stopped), so a case that stops a project and resumes
 * it later finds its room still free. Each wave fits both budgets that way. A case larger than
 * the room gets a wave of its own, and admission answers for it.
 */
trait E2eTestWavesTrait
{
    /** The e2e's pod next to an HTTP or queue case: curl (buildCurlManifest) or redis-cli XADD (buildXaddManifest). */
    public const E2E_HELPER_POD = ['cpu_m' => 50, 'memory_mb' => 32];

    /**
     * Running project pods seen before a wave's projects were deleted (pod name => [name, node]):
     * the node placement check looks at them as well as at what still runs at its turn.
     *
     * @var array<string, array{name: string, node: string|null}>
     */
    private array $e2eSeenProjectPods = [];

    /**
     * Runs the cases in waves: each case of a wave is set up, built, deployed and waited for
     * (runSingleTest, numbered across all waves), then $verify gets the wave (the cases under
     * their keys in $tests), then the wave's projects are deleted — except those $keep names,
     * which a later check uses and the caller deletes (releaseCases).
     *
     * @param  callable(array<int, array<string, mixed>> &$wave): void  $verify
     * @param  (callable(array<int, array<string, mixed>> $wave): list<int>)|null  $keep  keys of the cases whose projects stay
     */
    private function runTestsInWaves(array &$tests, string $regionId, callable $verify, ?callable $keep = null): void
    {
        $keys = array_keys($tests);
        $total = count($keys);
        $this->info('');
        $this->info("── Build & Deploy in waves ({$total} tests) ──");

        $start = 0;
        $wave = 0;
        while ($start < $total) {
            $region = $this->e2eMeasuredRegion($regionId);
            $sharesNodes = $region === null || empty($region->selected_workers);
            $demands = [];
            foreach (array_slice($keys, $start) as $key) {
                $case = $tests[$key]['config'] ?? [];
                $config = $this->buildProjectPayload($tests[$key]['name'], $case, $regionId)['config'];
                $demands[] = self::e2eCaseDemand($case['service_type'] ?? 'xaas', $config, $region, $sharesNodes);
            }
            $room = $this->e2eRoom($regionId);
            $size = self::e2eWaveSize($demands, $room);
            $waveKeys = array_slice($keys, $start, $size);
            $wave++;

            $this->line(sprintf('  Wave %d: tests %d–%d, %s of %s free', $wave, $start + 1, $start + $size,
                self::e2eAmount(self::e2eSum(array_slice($demands, 0, $size))), self::e2eAmount($room)));

            foreach ($waveKeys as $i => $key) {
                $tests[$key]['startTime'] = microtime(true);
                $this->runSingleTest($tests[$key], $start + $i + 1, $total);
                $tests[$key] = $this->finishTest($tests[$key]);
            }

            $batch = array_intersect_key($tests, array_flip($waveKeys));
            $verify($batch);
            foreach ($batch as $key => $test) {
                $tests[$key] = $test;
            }

            $kept = $keep ? $keep($batch) : [];
            $this->releaseCases(array_diff_key($batch, array_flip($kept)));
            foreach ($waveKeys as $key) {
                if (! in_array($key, $kept, true) && ! empty($tests[$key]['projectId'])) {
                    $tests[$key]['released'] = true;
                }
            }

            $start += $size;
        }
    }

    /**
     * How many of the next cases (their demands in order) the next wave takes: as many as fit
     * the room per resource, at least one. A resource with room null (capacity not measured) is
     * not limited.
     *
     * @param  list<array{cpu_m: int, memory_mb: int}>  $demands
     * @param  array{cpu_m: int|null, memory_mb: int|null}  $room
     */
    public static function e2eWaveSize(array $demands, array $room): int
    {
        $used = ResourceReservation::zero();
        $size = 0;
        foreach ($demands as $demand) {
            $next = ResourceReservation::add($used, $demand);
            foreach ($next as $key => $value) {
                if ($size > 0 && $room[$key] !== null && $value > $room[$key]) {
                    return $size;
                }
            }
            $used = $next;
            $size++;
        }

        return max(1, $size);
    }

    /**
     * What one case asks of the nodes while it is verified: its reservation as admission counts
     * it (ResourceReservation::demandForConfig on the config the project is created with: one
     * pod, the queue consumer, the RuntimeClass overhead of the region's mapping) plus the e2e's
     * curl / XADD pod of an HTTP or queue case when those run on the project nodes too (a region
     * without dedicated workers; dedicated workers are tainted for projects).
     *
     * @param  array<string, mixed>  $config  the project config of its create request (buildProjectPayload)
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function e2eCaseDemand(string $serviceType, array $config, ?KnativeRegion $region, bool $sharesNodes): array
    {
        $demand = ResourceReservation::forConfig($config, $serviceType, $region);
        $trigger = $config['trigger_type'] ?? 'http';

        return $sharesNodes && $trigger !== 'schedule' ? ResourceReservation::add($demand, self::E2E_HELPER_POD) : $demand;
    }

    /**
     * Deletes the projects of these cases (their reservations go with them) and waits until
     * their pods are gone, so the next wave finds the room it was planned with. The project pods
     * still running are noted first for the node placement check.
     *
     * @param  array<int|string, array<string, mixed>>  $cases
     */
    private function releaseCases(array $cases): void
    {
        $ids = array_values(array_filter(array_map(fn (array $t) => $t['projectId'] ?? null, $cases)));
        if ($ids === []) {
            return;
        }

        $this->noteProjectPods();

        $deleted = [];
        foreach ($ids as $id) {
            try {
                $res = $this->api()->timeout(120)->delete("/api/projects/{$id}");
                if ($res->successful()) {
                    $deleted[] = $id;
                } else {
                    $this->warn("  Project {$id} not deleted: HTTP {$res->status()} ".mb_substr((string) $res->json('message'), 0, 200));
                }
            } catch (\Throwable $e) {
                $this->warn("  Project {$id} not deleted: ".mb_substr($e->getMessage(), 0, 200));
            }
        }

        $left = $this->waitProjectPodsGone($deleted, 180);
        $this->line(sprintf('  Released %d project(s)%s', count($deleted), $left > 0 ? ", {$left} pod(s) still terminating after 180 s" : ''));
    }

    /** Waits until no pod of these projects is left in the region namespace; the pods left after $timeout seconds. */
    private function waitProjectPodsGone(array $projectIds, int $timeout): int
    {
        if ($projectIds === []) {
            return 0;
        }

        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $ids = array_flip($projectIds);
        $deadline = time() + $timeout;
        do {
            $left = 0;
            try {
                foreach ($k8s->listResources('v1', 'Pod', $ns)['items'] ?? [] as $pod) {
                    if (isset($ids[$pod['metadata']['labels']['runr/project-id'] ?? ''])) {
                        $left++;
                    }
                }
            } catch (\Throwable) {
                $left = 1; // a busy API server: the next poll
            }
            if ($left === 0) {
                return 0;
            }
            sleep(3);
        } while (time() < $deadline);

        return $left;
    }

    /** Notes the running project pods of the region namespace for the node placement check. */
    private function noteProjectPods(): void
    {
        try {
            $pods = $this->getE2eKubernetesClient()->listResources('v1', 'Pod', $this->getE2eNamespace())['items'] ?? [];
        } catch (\Throwable) {
            return;
        }
        foreach ($this->filterRunningPods($pods) as $pod) {
            $this->e2eSeenProjectPods[$pod['name']] = $pod;
        }
    }

    /**
     * The region with its capacity measured now (KnativeNodeService::refreshSchedulableCapacity,
     * what the periodic region status check runs): platform pods come and go during a run.
     */
    private function e2eMeasuredRegion(string $regionId): ?KnativeRegion
    {
        $region = KnativeRegion::find($regionId);
        if (! $region) {
            return null;
        }

        try {
            app(KnativeNodeService::class)->refreshSchedulableCapacity($region);
        } catch (\Throwable $e) {
            $this->warn('  Region capacity not measured: '.mb_substr($e->getMessage(), 0, 200));
        }

        return $region->fresh();
    }

    /**
     * What the region can run besides what its projects hold, per resource
     * (GET /knative-regions/{id}/capacity, e2eRoomOf()). Null for a resource whose capacity is not
     * known (0), or when the capacity cannot be read: no limit then.
     *
     * @return array{cpu_m: int|null, memory_mb: int|null}
     */
    private function e2eRoom(string $regionId): array
    {
        try {
            $res = $this->api()->get("/api/knative-regions/{$regionId}/capacity");
        } catch (\Throwable) {
            return ['cpu_m' => null, 'memory_mb' => null];
        }
        if (! $res->successful()) {
            $this->warn("  Region capacity not readable (HTTP {$res->status()}): the cases run in one wave");

            return ['cpu_m' => null, 'memory_mb' => null];
        }

        return self::e2eRoomOf((array) $res->json('data'));
    }

    /**
     * The room of a capacity answer: capacity − always-on used − FaaS used, both counted with the
     * region's stopped projects back (with_stopped) — a stopped project holds nothing of a
     * pay-as-you-go region, but one the run resumes needs its room again. An answer without
     * with_stopped (an older API) counts what holds the region now. Null: capacity not known.
     *
     * @param  array<string, mixed>  $data  the answer's data
     * @return array{cpu_m: int|null, memory_mb: int|null}
     */
    public static function e2eRoomOf(array $data): array
    {
        $room = ['cpu_m' => null, 'memory_mb' => null];
        foreach (array_keys($room) as $key) {
            $capacity = (int) ($data[$key]['capacity'] ?? 0);
            if ($capacity <= 0) {
                continue;
            }
            $held = is_array($data['with_stopped'][$key] ?? null) ? $data['with_stopped'][$key] : ($data[$key] ?? []);
            $room[$key] = max(0, $capacity - (int) ($held['always_on_used'] ?? 0) - (int) ($held['faas_used'] ?? 0));
        }

        return $room;
    }

    /**
     * @param  list<array{cpu_m: int, memory_mb: int}>  $demands
     * @return array{cpu_m: int, memory_mb: int}
     */
    private static function e2eSum(array $demands): array
    {
        return array_reduce($demands, fn (array $sum, array $d) => ResourceReservation::add($sum, $d), ResourceReservation::zero());
    }

    /** "2850m CPU / 2080 MB" ("no limit" for a resource without a measured capacity) */
    private static function e2eAmount(array $amount): string
    {
        $cpu = $amount['cpu_m'] ?? null;
        $memory = $amount['memory_mb'] ?? null;

        return ($cpu === null ? 'no limit' : "{$cpu}m").' CPU / '.($memory === null ? 'no limit' : "{$memory} MB");
    }
}
