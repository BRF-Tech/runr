<?php

namespace App\Services\Secrets;

use App\Models\Project;
use App\Models\ProjectSecret;
use App\Support\Secrets\SecretCipher;
use Illuminate\Support\Collection;

/**
 * Moves the secret values RunR's database still holds (written before pass-through, or with
 * secrets.storage = database) into the regions — `php artisan secrets:move-to-region`
 * (docs/upgrade.md). Per project of the current database context:
 *
 *  1. push — every value is written where the region keeps new values (ProjectSecrets::
 *     valueSource(): its OpenBao, or the Secret <slug>-secret-env) unless the same value is there;
 *  2. verify — the values are read back and every one compared through its keyed fingerprint;
 *  3. only then the database value becomes the encrypted copy kept for a rollback to 0.5.10
 *     (while RollbackCopies::kept(): the value stays, rollback_copy is set) or is cleared — the
 *     fingerprint kept and the variable's source set to where its value is now.
 *
 * Finalizing ($finalize) takes the copies too: each is checked in its backend against its
 * fingerprint (written again from the copy when the backend lost it), then cleared.
 *
 * A copy RunR 0.5.10 changed after a rollback holds a newer value (ProjectSecret::
 * hasRollbackCopy()): it is moved like a value written before pass-through, its version counted up.
 *
 * Idempotent and resumable: a row is done once its value is a copy or cleared; a project whose
 * region does not answer, or that has no region cluster, keeps its values and is reported — run
 * the command again. Values pass through memory only and are never printed.
 */
class SecretValueMover
{
    /**
     * @param  bool  $dryRun  report what would move, change nothing (not even the region)
     * @param  bool  $finalize  also check and clear the copies kept for a rollback
     * @return array{moved: int, verified_already: int, projects: int, copies_kept: int, copies_dropped: int, skipped: list<string>, failed: list<string>}
     */
    public function move(bool $dryRun = false, ?string $projectId = null, bool $finalize = false): array
    {
        $result = ['moved' => 0, 'verified_already' => 0, 'projects' => 0, 'copies_kept' => 0, 'copies_dropped' => 0, 'skipped' => [], 'failed' => []];
        $keepCopies = ! $finalize && RollbackCopies::kept();

        $rows = $this->withValue($projectId)
            ->filter(fn (ProjectSecret $s) => $finalize || $s->isHeldInDatabase())
            ->groupBy('project_id');

        foreach ($rows as $id => $secrets) {
            $project = Project::query()->with('knativeRegion')->find($id);
            if (! $project) {
                continue;
            }
            $result['projects']++;
            $scope = SecretScope::forProject($project);
            $held = $secrets->filter(fn (ProjectSecret $s) => $s->isHeldInDatabase())->count();
            $copies = $secrets->count() - $held;

            if (! $scope->hasCluster()) {
                $result['skipped'][] = "{$project->slug}: no region cluster ({$secrets->count()} value(s) stay in the database until the project has one)";

                continue;
            }
            if ($dryRun) {
                $result['moved'] += $held;
                $result['copies_kept'] += $keepCopies ? $held : 0;
                $result['copies_dropped'] += $copies;

                continue;
            }

            try {
                $counts = $this->moveProject($scope, $secrets, $keepCopies);
                $result['moved'] += $counts['moved'];
                $result['verified_already'] += $counts['already'];
                $result['copies_kept'] += $keepCopies ? $held : 0;
                $result['copies_dropped'] += $copies;
            } catch (SecretBackendException $e) {
                $result['failed'][] = "{$project->slug}: {$e->getMessage()}";
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, ProjectSecret>  $secrets  rows with a database value (held, or copies when finalizing)
     * @return array{moved: int, already: int}
     *
     * @throws SecretBackendException nothing of the project was changed
     */
    private function moveProject(SecretScope $scope, Collection $secrets, bool $keepCopies): array
    {
        $newValues = ProjectSecrets::valueSource($scope->region);
        $backends = app(SecretBackends::class);

        // Where each value has to be: a value the database holds goes where the region keeps new
        // values; a copy is checked where its value is
        $targets = [];
        $fingerprints = [];
        $changed = [];
        foreach ($secrets as $secret) {
            $held = $secret->isHeldInDatabase();
            $targets[$secret->name] = $held ? $newValues : ($secret->source ?: ProjectSecret::SOURCE_REGION);
            if ($held) {
                $value = (string) $secret->value;
                $fingerprints[$secret->name] = $secret->fingerprint && SecretFingerprint::matches($value, $secret->fingerprint)
                    ? $secret->fingerprint
                    : SecretFingerprint::of($value);
                // A value 0.5.10 changed after a rollback: a new version of the variable
                $changed[$secret->name] = $secret->fingerprint !== null && $fingerprints[$secret->name] !== $secret->fingerprint;
            } else {
                $fingerprints[$secret->name] = (string) $secret->fingerprint;
                $changed[$secret->name] = false;
            }
        }
        unset($value);

        $moved = 0;
        $already = 0;
        $byName = $secrets->keyBy('name');
        foreach (array_unique($targets) as $target) {
            $backend = $backends->for($target);
            $names = array_keys(array_filter($targets, fn ($t) => $t === $target));
            $current = $backend->readValues($scope, $names);

            $push = [];
            foreach ($names as $name) {
                $held = $byName[$name]->isHeldInDatabase();
                if (array_key_exists($name, $current) && SecretFingerprint::matches($current[$name], $fingerprints[$name])) {
                    $already += $held ? 1 : 0;

                    continue;
                }
                // The backend does not hold the value: the database's (the copy when finalizing)
                try {
                    $push[$name] = SecretCipher::decrypt((string) $byName[$name]->getRawOriginal('value'));
                } catch (\Throwable) {
                    throw SecretBackendException::missing($scope->region?->name, [$name]);
                }
                $moved += $held ? 1 : 0;
            }
            unset($current);

            if ($push !== []) {
                $backend->write($scope, $push);
            }
            unset($push);

            // Verify what the backend holds now before anything is changed in the database
            $after = $backend->readValues($scope, $names);
            $mismatch = [];
            foreach ($names as $name) {
                if (! array_key_exists($name, $after) || ! SecretFingerprint::matches($after[$name], $fingerprints[$name])) {
                    $mismatch[] = $name;
                }
            }
            unset($after);
            if ($mismatch !== []) {
                throw SecretBackendException::missing($scope->region?->name, $mismatch);
            }
        }

        $scope->project->getConnection()->transaction(function () use ($secrets, $fingerprints, $targets, $changed, $keepCopies) {
            foreach ($secrets as $secret) {
                // Not a change of the variable: no audit record, updated_at stays
                $secret->timestamps = false;
                $secret->forceFill(array_merge(
                    // The value stays as the copy for a rollback (it matches the fingerprint) or goes
                    $keepCopies ? ['rollback_copy' => true] : ['value' => null, 'rollback_copy' => false],
                    ['fingerprint' => $fingerprints[$secret->name], 'source' => $targets[$secret->name]],
                    $changed[$secret->name] ? ['version' => ((int) $secret->version) + 1] : [],
                ))->saveQuietly();
            }
        });

        return ['moved' => $moved, 'already' => $already];
    }

    /** How many values the current database context still holds only there (of one project). */
    public function remaining(?string $projectId = null): int
    {
        return $this->withValue($projectId)->filter(fn (ProjectSecret $s) => $s->isHeldInDatabase())->count();
    }

    /** How many copies for a rollback the current database context keeps (of one project). */
    public function copies(?string $projectId = null): int
    {
        return $this->withValue($projectId)->filter(fn (ProjectSecret $s) => $s->hasRollbackCopy())->count();
    }

    /** @return Collection<int, ProjectSecret> the variables with a database value */
    private function withValue(?string $projectId): Collection
    {
        return ProjectSecret::query()
            ->where('source', '!=', ProjectSecret::SOURCE_EXTERNAL)
            ->whereNotNull('value')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->get();
    }
}
