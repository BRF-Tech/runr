<?php

namespace App\Services\OrgTransfer\Contracts;

use App\Models\KnativeRegion;

/**
 * The organization's quota for the projects an import creates in a region
 * (docs/org-export.md#the-plan). Region capacity is checked by the import itself (as for every
 * new project); a quota per organization exists with the Tenancy module only, which binds an
 * implementation — without it nothing is limited here (NoImportAdmission).
 */
interface ImportAdmission
{
    /**
     * Why the projects planned into $region so far (this one last) do not fit the organization's
     * quota there; null when they do.
     *
     * @param  list<array{service_type: string, config: array<string, mixed>}>  $demands
     */
    public function check(KnativeRegion $region, array $demands): ?string;
}
