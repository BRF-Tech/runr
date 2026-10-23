<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\GitDeploy\TenantContext;
use App\Services\KubernetesClient;
use App\Triggers\Cleanup\CdcCleanupPod;
use App\Triggers\Cleanup\CdcSlotCleanup;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Drops the replication slots (and the publication RunR created) a cdc trigger left on its
 * database (CdcSlotCleanup, docs/triggers.md#cdc-cleanup):
 *
 *  1. waits while a deployed event gateway still reads a slot — its config Secret
 *     <workload>-qc-config lists the slot (the trigger change reaches the gateway with the next
 *     deploy): it looks again every RECHECK_SECONDS, for up to WAIT_DAYS;
 *  2. leaves out the slots another cdc trigger of the organization reads now;
 *  3. runs the drop in the project's region (CdcCleanupPod) — up to MAX_RUNS times while the
 *     database cannot be reached or a slot is still in use;
 *  4. notifies the user who made the change: what was dropped, or what is left and the SQL to drop
 *     it by hand.
 *
 * Encrypted queue payload: it carries the trigger's DSN. Each step that has to wait dispatches the
 * next run (with its counters) instead of releasing this one.
 */
class DropCdcSlotsJob implements ShouldBeEncrypted, ShouldQueue
{
    use BroadcastsJobCompletion, Queueable;

    /** How long a slot a deployed gateway still reads is waited for (until the project's next deploy). */
    public const WAIT_DAYS = 7;

    public const RECHECK_SECONDS = 600;

    /** Runs of the drop while the database does not answer or a slot is in use. */
    public const MAX_RUNS = 3;

    public const RETRY_SECONDS = 300;

    public int $tries = 1;

    public int $timeout = 300;

    public string $deadline;

    /**
     * @param  array<string, string>  $podLabels  labels of the project's pods (its network identity)
     * @param  list<array{0: string, 1: string|null}>  $workloads  [workload, region id] whose gateways may read the slots
     * @param  array{dsn: string, slots: list<string>, publication: string, drop_publication: bool}  $spec
     */
    public function __construct(
        public ?string $tenantId,
        public string $projectId,
        public string $projectSlug,
        public string $regionId,
        public array $podLabels,
        public array $workloads,
        public string $trigger,
        public array $spec,
        public string $reason,
        ?string $initiatorId = null,
        public int $runs = 0,
        ?string $deadline = null,
    ) {
        $this->initiatorId = $initiatorId;
        $this->deadline = $deadline ?? CarbonImmutable::now()->addDays(self::WAIT_DAYS)->toIso8601String();
    }

    public function handle(CdcSlotCleanup $cleanup, CdcCleanupPod $pod): void
    {
        TenantContext::run($this->tenantId, fn () => $this->run($cleanup, $pod));
    }

    private function run(CdcSlotCleanup $cleanup, CdcCleanupPod $pod): void
    {
        $region = KnativeRegion::query()->find($this->regionId);
        if (! $region || $region->is_demo) {
            return; // a demo region runs no gateway
        }

        // Slots another cdc trigger of the organization reads now are not ours to drop
        $wanted = $this->reason === 'project_deleted' ? $cleanup->wantedSlots($this->projectId) : $cleanup->wantedSlots();
        $slots = array_values(array_filter($this->spec['slots'], fn (string $s) => ! isset($wanted[$s])));
        if ($slots === [] && ! $this->spec['drop_publication']) {
            return;
        }

        try {
            $reader = $this->deployedReader($slots);
        } catch (\Throwable $e) {
            $this->again("the region's cluster did not answer: ".$e->getMessage());

            return;
        }
        if ($reader !== null) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo(CarbonImmutable::parse($this->deadline))) {
                $this->report(false, __('triggers.cleanup.still_read', ['workload' => $reader, 'days' => self::WAIT_DAYS]), $slots);

                return;
            }
            $this->next(self::RECHECK_SECONDS, $this->runs, __('triggers.cleanup.reading', ['workload' => $reader]), $slots);

            return;
        }

        $outcome = $pod->run($region, $this->podLabels, ['slots' => $slots] + $this->spec);
        $result = $outcome['result'] ?? null;
        if (! is_array($result)) {
            $this->again((string) ($outcome['error'] ?? 'the cleanup pod reported nothing'), $slots);

            return;
        }
        if (! ($result['reachable'] ?? false)) {
            $this->again(__('triggers.cleanup.unreachable', ['database' => $result['database'] ?? '?', 'error' => $result['error'] ?? '']), $slots);

            return;
        }

        $left = array_values(array_filter($result['slots'] ?? [], fn ($s) => ! in_array($s['status'] ?? '', ['dropped', 'absent'], true)));
        if ($left !== [] && collect($left)->contains(fn ($s) => ($s['status'] ?? '') === 'in_use') && $this->runs + 1 < self::MAX_RUNS) {
            $this->next(self::RETRY_SECONDS, $this->runs + 1, $this->summary($result), $slots, $result);

            return;
        }

        $this->report($left === [] && (($result['publication']['status'] ?? null) !== 'failed'), $this->summary($result), $slots, $result);
    }

    /**
     * The workload whose deployed gateway still lists one of the slots (null: none does).
     *
     * @param  list<string>  $slots
     */
    private function deployedReader(array $slots): ?string
    {
        foreach ($this->workloads as [$workload, $regionId]) {
            $region = $regionId !== null ? KnativeRegion::query()->find($regionId) : null;
            if (! $region || ! $region->kubeconfig || $region->is_demo) {
                continue;
            }
            $secret = (new KubernetesClient($region->kubeconfig))
                ->getResourceStatus('v1', 'Secret', $workload.'-qc-config', $region->project_namespace ?? $region->name);
            $raw = isset($secret['data']['gateway.json']) ? base64_decode((string) $secret['data']['gateway.json'], true) : false;
            $config = is_string($raw) ? json_decode($raw, true) : null;
            foreach ((array) ($config['triggers'] ?? []) as $entry) {
                if (($entry['kind'] ?? null) === 'cdc' && in_array($entry['source']['slot'] ?? null, $slots, true)) {
                    return $workload;
                }
            }
        }

        return null;
    }

    /** A failed run: try again later, or report after MAX_RUNS. */
    private function again(string $error, ?array $slots = null): void
    {
        if ($this->runs + 1 < self::MAX_RUNS) {
            Log::info('CDC cleanup: trying again', ['project' => $this->projectSlug, 'trigger' => $this->trigger, 'error' => $error]);
            $this->next(self::RETRY_SECONDS, $this->runs + 1, $error, $slots ?? $this->spec['slots']);

            return;
        }
        $this->report(false, $error, $slots ?? $this->spec['slots']);
    }

    /**
     * The next run after $delay. A queue that runs jobs at once (the sync driver) cannot wait: the
     * outcome so far ($why) is reported instead, with the SQL to finish by hand.
     *
     * @param  list<string>  $slots
     */
    private function next(int $delay, int $runs, string $why, array $slots, ?array $result = null): void
    {
        $connection = $this->connection ?? config('queue.default');
        if (config("queue.connections.{$connection}.driver") === 'sync') {
            $this->report(false, $why, $slots, $result);

            return;
        }

        self::dispatch(
            $this->tenantId, $this->projectId, $this->projectSlug, $this->regionId, $this->podLabels, $this->workloads,
            $this->trigger, $this->spec, $this->reason, $this->initiatorId, $runs, $this->deadline,
        )->delay(now()->addSeconds($delay));
    }

    /** One line per slot and the publication: "runr_x dropped; runr_y absent; publication p kept (…)". */
    private function summary(array $result): string
    {
        $parts = [];
        foreach ((array) ($result['slots'] ?? []) as $s) {
            $parts[] = trim(($s['name'] ?? '?').' '.($s['status'] ?? '?').(! empty($s['detail']) ? ' ('.$s['detail'].')' : ''));
        }
        if (is_array($result['publication'] ?? null)) {
            $p = $result['publication'];
            $parts[] = trim('publication '.($p['name'] ?? '?').' '.($p['status'] ?? '?').(! empty($p['detail']) ? ' ('.$p['detail'].')' : ''));
        }

        return implode('; ', $parts);
    }

    /**
     * The outcome to the log and, as a notification, to the user who made the change; what is left
     * comes with the SQL to drop it by hand.
     *
     * @param  list<string>  $slots
     */
    private function report(bool $ok, string $detail, array $slots, ?array $result = null): void
    {
        $payload = ['project_id' => $this->projectId, 'project' => $this->projectSlug, 'trigger' => $this->trigger, 'slots' => $slots, 'reason' => $this->reason];

        if ($ok) {
            $title = __('triggers.cleanup.done_title', ['trigger' => $this->trigger, 'project' => $this->projectSlug]);
            Log::info("CDC cleanup: {$title}: {$detail}");
            $this->broadcastSuccess('trigger.cdc_cleanup.done', $payload + ['message' => $detail], mb_substr($title, 0, 255), $detail);

            return;
        }

        $sql = $this->manualSql($slots, $result);
        $title = __('triggers.cleanup.failed_title', ['trigger' => $this->trigger, 'project' => $this->projectSlug]);
        $body = rtrim($detail, '.').'. '.__('triggers.cleanup.manual', ['sql' => $sql]);
        Log::warning("CDC cleanup: {$title}: {$body}");
        $this->broadcastFailure('trigger.cdc_cleanup.failed', $payload + ['message' => $body, 'sql' => $sql], new \RuntimeException($detail), mb_substr($title, 0, 255), $body);
    }

    /** The statements that drop what is left. */
    private function manualSql(array $slots, ?array $result): string
    {
        $left = $slots;
        if (is_array($result['slots'] ?? null)) {
            $left = array_values(array_map(fn ($s) => (string) ($s['name'] ?? ''), array_filter($result['slots'], fn ($s) => ! in_array($s['status'] ?? '', ['dropped', 'absent'], true))));
        }
        $sql = array_map(fn (string $s) => "SELECT pg_drop_replication_slot('".str_replace("'", "''", $s)."');", $left);
        $publication = (string) ($this->spec['publication'] ?? '');
        $pubDone = in_array($result['publication']['status'] ?? null, ['dropped', 'absent', 'kept'], true);
        if ($this->spec['drop_publication'] && $publication !== '' && ! $pubDone) {
            $sql[] = 'DROP PUBLICATION IF EXISTS "'.str_replace('"', '""', $publication).'";';
        }

        return implode(' ', $sql);
    }
}
