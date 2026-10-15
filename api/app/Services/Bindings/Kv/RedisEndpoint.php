<?php

namespace App\Services\Bindings\Kv;

use App\Services\Bindings\Ops\OpsTask;

/**
 * The Redis KV bindings run on, as seen from inside the region: the managed KV Redis (runr-kv,
 * ACL users persisted with ACL SAVE) or the region's own Redis (ACL users re-applied by the
 * reconcile job; without ACL support: one logical DB per binding).
 */
final class RedisEndpoint
{
    public function __construct(
        public string $host,
        public int $port,
        public string $adminPassword,
        public bool $acl,
        public bool $persistAcl,
        public string $mode,
    ) {}

    /** redis-cli connection arguments (password via REDISCLI_AUTH) */
    public function cliArgs(int $db = 0): string
    {
        $args = '-h '.OpsTask::quote($this->host).' -p '.$this->port.' --no-auth-warning';
        if ($this->acl) {
            $args .= ' --user default';
        }
        if ($db > 0) {
            $args .= ' -n '.$db;
        }

        return $args;
    }
}
