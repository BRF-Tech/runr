<?php

namespace App\Services\RegionReconcile;

use App\Models\KnativeRegion;
use Closure;

/**
 * One region row to reconcile, plus how to run code in the database context the row belongs to:
 * central rows run as-is, a tenant-owned row (Tenancy module) runs inside $tenant->run() so its
 * writes (prometheus_url, agent_token_hash, …) land in that tenant's schema.
 */
final class ReconcileTarget
{
    /**
     * @param  Closure(callable): mixed|null  $runner
     */
    public function __construct(
        public readonly KnativeRegion $region,
        public readonly string $scope = 'central',
        private readonly ?Closure $runner = null,
    ) {}

    /**
     * Run $fn in this row's database context.
     */
    public function run(callable $fn): mixed
    {
        return $this->runner ? ($this->runner)($fn) : $fn();
    }

    public function label(): string
    {
        return "{$this->region->name} ({$this->region->id}, {$this->scope})";
    }
}
