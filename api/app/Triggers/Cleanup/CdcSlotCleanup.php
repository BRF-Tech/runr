<?php

namespace App\Triggers\Cleanup;

use App\Jobs\DropCdcSlotsJob;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectTrigger;
use App\Services\GitDeploy\TenantContext;
use App\Services\GlobalProjects\GlobalProjectRegions;
use App\Services\InternalNetwork\InternalNetwork;
use App\Triggers\Types\Sources\CdcTrigger;
use Illuminate\Support\Facades\Log;

/**
 * A cdc trigger reads Postgres through a replication slot, and Postgres keeps WAL for a slot until
 * it is read — a slot nobody reads any more fills the database's disk. When a cdc trigger goes,
 * RunR drops what it left on the database (docs/triggers.md#cdc-cleanup):
 *
 *  - the trigger is removed (DELETE, a PUT / runr.yaml list without it), renamed, or its slot or
 *    database changes: the slots it read — its own slot, or the default runr_<workload>_<trigger>
 *    of the project's workload and of each environment's — and the publication, when the trigger
 *    created it (create_publication) and RunR marked it as its own;
 *  - its project is deleted: every cdc trigger's slots and publications;
 *  - an environment is deleted / the project renamed: the default slots of the old workload name.
 *
 * Nothing is dropped while an event gateway still reads a slot: a trigger change reaches the
 * gateway with the project's next deploy, so DropCdcSlotsJob waits until no deployed gateway config
 * lists the slot (up to DropCdcSlotsJob::WAIT_DAYS), and never drops a slot another cdc trigger of
 * the organization reads. The drop runs in the project's region, as the gateway reaches the
 * database: a one-shot pod of the gateway image (GATEWAY_MODE=cdc-cleanup, CdcCleanupPod). The
 * user who made the change gets a notification with the outcome — and, when the database cannot be
 * reached, the SQL to run there.
 *
 * Scoped per request (TriggerServiceProvider): takeNotes() hands out the cleanups the request
 * scheduled, for the API's answer.
 */
class CdcSlotCleanup
{
    /** @var list<array<string, mixed>> */
    private array $notes = [];

    /** The trigger was removed. */
    public function removed(Project $project, ProjectTrigger $trigger): void
    {
        $this->schedule($project, $trigger, $this->slotsOf($project, $trigger), (bool) $trigger->setting('create_publication'), 'removed');
    }

    /** A rename, another slot, publication or database: what the trigger no longer reads goes. */
    public function changed(Project $project, ProjectTrigger $before, ProjectTrigger $after): void
    {
        $sameDatabase = $before->secret('dsn') === $after->secret('dsn');
        $kept = $sameDatabase ? $this->slotsOf($project, $after) : [];
        $gone = array_values(array_diff($this->slotsOf($project, $before), $kept));
        $publication = (bool) $before->setting('create_publication')
            && (! $sameDatabase || $before->setting('publication') !== $after->setting('publication'));

        if ($gone !== [] || $publication) {
            $this->schedule($project, $before, $gone, $publication, 'changed');
        }
    }

    /** The project is deleted (ProjectCleanupService): every cdc trigger goes with it. */
    public function projectDeleted(Project $project): void
    {
        foreach ($this->cdcTriggers($project) as $trigger) {
            $this->schedule($project, $trigger, $this->slotsOf($project, $trigger), (bool) $trigger->setting('create_publication'), 'project_deleted');
        }
    }

    /** An environment's workload left its region: the default slots its gateway read. */
    public function environmentRemoved(Project $project, string $workload, ?string $regionId): void
    {
        foreach ($this->cdcTriggers($project) as $trigger) {
            if (! $trigger->setting('slot')) {
                $this->schedule($project, $trigger, [CdcTrigger::defaultSlot($workload, $trigger->name)], false, 'environment_deleted', $regionId);
            }
        }
    }

    /** The project got a new slug: the default slots of the old one. */
    public function projectRenamed(Project $project, string $oldSlug): void
    {
        foreach ($this->cdcTriggers($project) as $trigger) {
            if (! $trigger->setting('slot')) {
                $this->schedule($project, $trigger, [CdcTrigger::defaultSlot($oldSlug, $trigger->name)], false, 'renamed');
            }
        }
    }

    /** @return list<array<string, mixed>> the cleanups scheduled since the last call (they are handed out once) */
    public function takeNotes(): array
    {
        [$notes, $this->notes] = [$this->notes, []];

        return $notes;
    }

    /**
     * The slots a cdc trigger reads: its own slot, else the default one of every workload of the
     * project (its slug in each region it runs in, each environment's workload).
     *
     * @return list<string>
     */
    public function slotsOf(Project $project, ProjectTrigger $trigger): array
    {
        $own = $trigger->setting('slot');
        if (is_string($own) && $own !== '') {
            return [$own];
        }

        return array_values(array_unique(array_map(
            fn (array $w) => CdcTrigger::defaultSlot($w[0], (string) $trigger->name),
            self::workloads($project),
        )));
    }

    /**
     * The workloads of a project that run an event gateway: [name, region id] — its slug in each
     * region it runs in, each environment's workload in the environment's region.
     *
     * @return list<array{0: string, 1: string|null}>
     */
    public static function workloads(Project $project): array
    {
        $out = [];
        foreach (GlobalProjectRegions::of($project) as $region) {
            $out[] = [(string) $project->slug, (string) $region->getKey()];
        }
        if ($out === []) {
            $out[] = [(string) $project->slug, $project->knative_region_id !== null ? (string) $project->knative_region_id : null];
        }

        try {
            if (ProjectEnvironment::tablesReady()) {
                foreach (ProjectEnvironment::query()->where('project_id', $project->getKey())->where('is_primary', false)->get() as $environment) {
                    $out[] = [(string) $environment->workload_name, $environment->regionId()];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('CDC cleanup: environments not listed', ['project' => $project->slug, 'error' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Every slot the organization's cdc triggers read now (explicit or default, every workload):
     * DropCdcSlotsJob never drops one of them. $ignoreProjectId: a project being deleted, whose rows
     * may still be there.
     *
     * @return array<string, true>
     */
    public function wantedSlots(?string $ignoreProjectId = null): array
    {
        $wanted = [];
        try {
            $triggers = ProjectTrigger::query()->where('type', 'cdc')
                ->when($ignoreProjectId !== null, fn ($q) => $q->where('project_id', '!=', $ignoreProjectId))
                ->with('project')->get();
        } catch (\Throwable) {
            return [];
        }
        foreach ($triggers as $trigger) {
            if ($trigger->project) {
                foreach ($this->slotsOf($trigger->project, $trigger) as $slot) {
                    $wanted[$slot] = true;
                }
            }
        }

        return $wanted;
    }

    /** @return iterable<ProjectTrigger> */
    private function cdcTriggers(Project $project): iterable
    {
        try {
            return $project->triggers()->where('type', 'cdc')->get();
        } catch (\Throwable) {
            return []; // a schema without project_triggers
        }
    }

    /** @param  list<string>  $slots */
    private function schedule(Project $project, ProjectTrigger $trigger, array $slots, bool $dropPublication, string $reason, ?string $regionId = null): void
    {
        $dsn = $trigger->secret('dsn');
        $publication = (string) $trigger->setting('publication', '');
        if (! is_string($dsn) || $dsn === '' || ($slots === [] && ! $dropPublication)) {
            return;
        }

        $regionId ??= $project->knative_region_id !== null ? (string) $project->knative_region_id : null;
        if ($regionId === null) {
            return; // never deployed anywhere: no gateway created a slot
        }

        $spec = [
            'dsn' => $dsn,
            'slots' => $slots,
            'publication' => $dropPublication ? $publication : '',
            'drop_publication' => $dropPublication && $publication !== '',
        ];
        // who hears the outcome: the user who made the change, else (a git push, a job) the
        // project's owner
        $user = auth()->id() ?? $project->owner_id ?? $project->created_by;

        DropCdcSlotsJob::dispatch(
            TenantContext::currentId(),
            (string) $project->getKey(),
            (string) $project->slug,
            $regionId,
            ['runr/project-id' => (string) $project->getKey()] + InternalNetwork::podLabels($project),
            self::workloads($project),
            (string) $trigger->name,
            $spec,
            $reason,
            $user !== null ? (string) $user : null,
        );

        $this->notes[] = [
            'trigger' => (string) $trigger->name,
            'type' => 'cdc',
            'slots' => $slots,
            'publication' => $spec['drop_publication'] ? $publication : null,
            'status' => 'scheduled',
            'message' => __('triggers.cleanup.scheduled', [
                'slots' => implode(', ', $slots) ?: '-',
                'publication' => $spec['drop_publication'] ? __('triggers.cleanup.and_publication', ['publication' => $publication]) : '',
            ]),
        ];
    }
}
