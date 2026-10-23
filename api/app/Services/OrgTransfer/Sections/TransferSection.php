<?php

namespace App\Services\OrgTransfer\Sections;

use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;

/**
 * One part of an organization package (docs/org-export.md#sections). The export runs every
 * section in order, then packs; the plan asks every section what it would do; the import runs
 * them in the same order — so a section may use what an earlier one created (projects map
 * bindings, alert rules map projects).
 *
 * A feature adds its own data with a section of its own (SectionRegistry::extend()): its entries
 * live under extensions/<key>/, and an importer without that section reports them as not
 * imported instead of failing.
 */
interface TransferSection
{
    /** Unique, lowercase: the manifest's `sections` list and the item records use it. */
    public function key(): string;

    /** Write the section's entries (ExportContext::putJson() / path()); secrets go to addSecret(). */
    public function export(ExportContext $context): void;

    /** What importing the section would do, and what blocks it. */
    public function plan(ImportContext $context, ImportPlan $plan): void;

    /** Create the section's entries; per entry once (ImportContext::settled() / record()). */
    public function import(ImportContext $context): void;
}
