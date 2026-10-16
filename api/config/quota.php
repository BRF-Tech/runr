<?php

/*
|--------------------------------------------------------------------------
| Organization CPU / RAM ceiling
|--------------------------------------------------------------------------
|
| Install-form inputs (Helm chart values quota.cpu / quota.memoryGiB → env QUOTA_CPU /
| QUOTA_MEMORY_GIB). Empty or 0 = no ceiling for that resource. Read through
| App\Services\Helpers\OrganizationCeiling.
|
| - Applied as a ResourceQuota + LimitRange in each Region's project namespace
|   (App\Services\RegionNamespaceQuotaService::applyNamespaceQuota).
| - Multi-tenant edition: default tenant quota on a Region, and the upper bound of the sum of
|   all tenants' quotas on a Region (quotas are shares of the Region capacity; admission inside a
|   quota overcommits scale-to-zero workloads by the Region's factors, TenantQuotaService).
|
*/

return [
    'cpu' => env('QUOTA_CPU'),        // vCPU, e.g. 8 or 0.5
    'memory_gib' => env('QUOTA_MEMORY_GIB'), // GiB, e.g. 16
];
