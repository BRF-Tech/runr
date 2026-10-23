<?php

namespace App\Triggers;

use App\Services\Observability\TenantScope;

/**
 * The organization context of a trigger URL (webhook inbox, gateway inbox): the tenant id, or
 * "central" for the central database. Requests from senders and from the event gateway carry no
 * user, so the context comes from the path.
 */
final class TriggerContexts
{
    public const CENTRAL = 'central';

    public const PATTERN = '[A-Za-z0-9_-]{1,64}';

    public static function current(): string
    {
        return TenantScope::current() ?? self::CENTRAL;
    }

    /**
     * Run $fn in the context of $key; null when the tenant does not exist (or tenancy is off and
     * $key names a tenant).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T|null
     */
    public static function run(string $key, callable $fn): mixed
    {
        return TenantScope::run($key === self::CENTRAL ? null : $key, $fn);
    }
}
