<?php

namespace App\Services\OrgTransfer\Secrets;

use App\Models\Project;

/**
 * Where the export reads a project's secret variables and the import writes them
 * (docs/org-export.md#secrets). The package never depends on where the values live: today they
 * are encrypted in project_secrets (DatabaseSecretValues); when values live in the region (or in
 * an external store and only a reference is kept), a binding of this interface reads and writes
 * them there — the export and import code stays the same.
 */
interface SecretValues
{
    /**
     * The project's secret variables: values RunR can read (name => value), references to
     * external stores, whose values RunR never sees (name => reference settings), and the names
     * whose value could not be read (a region that did not answer).
     *
     * @return array{values: array<string, string>, references: array<string, array<string, mixed>>, unreadable: list<string>}
     */
    public function read(Project $project): array;

    /**
     * Set secret variables of a project (name => value); returns the names set.
     *
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public function write(Project $project, array $values, mixed $userId): array;

    /**
     * Recreate references to external stores; returns the names it could not recreate (a store
     * of the source organization that does not exist here).
     *
     * @param  array<string, array<string, mixed>>  $references
     * @return list<string>
     */
    public function writeReferences(Project $project, array $references, mixed $userId): array;
}
