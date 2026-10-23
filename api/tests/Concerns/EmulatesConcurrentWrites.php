<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * A write of another request between a check and the write that relies on it: $write runs once,
 * right after the first query whose SQL contains $needle — outside the transaction (savepoint) the
 * code under test opens for its own write, as a concurrent request's committed row would be.
 */
trait EmulatesConcurrentWrites
{
    protected function afterQuery(string $needle, Closure $write): void
    {
        $done = false;
        DB::listen(function (QueryExecuted $query) use (&$done, $needle, $write) {
            if ($done || ! str_contains($query->sql, $needle)) {
                return;
            }
            $done = true;
            $write();
        });
    }
}
