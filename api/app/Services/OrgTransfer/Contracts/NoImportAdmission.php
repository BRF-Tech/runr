<?php

namespace App\Services\OrgTransfer\Contracts;

use App\Models\KnativeRegion;

/** No organization quota (single-tenant): region capacity is all that limits an import. */
final class NoImportAdmission implements ImportAdmission
{
    public function check(KnativeRegion $region, array $demands): ?string
    {
        return null;
    }
}
