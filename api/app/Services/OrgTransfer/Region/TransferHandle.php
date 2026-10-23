<?php

namespace App\Services\OrgTransfer\Region;

use App\Models\KnativeRegion;

/** A running transfer pod: its region, namespace, name (Job, Service, Secret) and access token. */
final class TransferHandle
{
    public function __construct(
        public readonly KnativeRegion $region,
        public readonly string $namespace,
        public readonly string $name,
        public readonly string $token,
        public readonly bool $hasSecret,
    ) {}
}
