<?php

namespace App\Services\Bindings\Kv;

use App\Models\Binding;
use App\Services\Bindings\Ops\OpsTask;

/**
 * redis-cli operations of KV bindings as OpsTasks (commands from a file, admin password via
 * REDISCLI_AUTH — never on a command line).
 *
 * A binding's ACL user may use every command on keys / channels under its prefix except:
 * admin and dangerous commands (CONFIG, FLUSHALL, KEYS, DEBUG, …), SELECT, FUNCTION, SCRIPT
 * FLUSH/KILL and the commands that list other bindings' key or channel names (SCAN, RANDOMKEY,
 * PUBSUB CHANNELS/NUMSUB/…). INFO stays allowed (client libraries' ready checks).
 */
class RedisScripts
{
    public const DENIED = '-@admin -@dangerous +info -select -function -script|flush -script|kill -scan -randomkey '
        .'-pubsub|channels -pubsub|numpat -pubsub|numsub -pubsub|shardchannels -pubsub|shardnumsub';

    /** Over the memory limit: read and delete only */
    public const READ_ONLY = '-@write +del +unlink +expire +pexpire +persist';

    public const USAGE_LUA = <<<'LUA'
local cursor = "0"
local bytes = 0
local keys = 0
repeat
  local r = redis.call("SCAN", cursor, "MATCH", ARGV[1], "COUNT", 1000)
  cursor = r[1]
  for _, k in ipairs(r[2]) do
    bytes = bytes + (redis.call("MEMORY", "USAGE", k, "SAMPLES", "0") or 0)
    keys = keys + 1
  end
until cursor == "0"
return {bytes, keys}
LUA;

    public const PURGE_LUA = <<<'LUA'
local cursor = "0"
local n = 0
repeat
  local r = redis.call("SCAN", cursor, "MATCH", ARGV[1], "COUNT", 1000)
  cursor = r[1]
  for _, k in ipairs(r[2]) do
    redis.call("UNLINK", k)
    n = n + 1
  end
until cursor == "0"
return n
LUA;

    /**
     * ACL SETUSER that (re)defines the user from scratch with every given password.
     *
     * @param  list<string>  $passwords
     */
    public static function setUser(Binding $binding, array $passwords, bool $readOnly = false, bool $enabled = true): string
    {
        $prefix = $binding->kvPrefix();
        $parts = ['ACL SETUSER', $binding->resource_name, 'reset', $enabled ? 'on' : 'off'];
        foreach (array_unique(array_filter($passwords)) as $password) {
            $parts[] = '>'.$password;
        }
        $parts[] = '~'.$prefix.'*';
        $parts[] = '&'.$prefix.'*';
        $parts[] = '+@all';
        $parts[] = self::DENIED;
        if ($readOnly) {
            $parts[] = self::READ_ONLY;
        }

        return implode(' ', $parts);
    }

    /**
     * Run commands (one per line) against the endpoint. Fails on any error reply.
     *
     * @param  list<string>  $commands
     */
    public static function commands(RedisEndpoint $endpoint, string $image, string $purpose, array $commands, int $db = 0): OpsTask
    {
        if ($endpoint->persistAcl && self::touchesAcl($commands)) {
            $commands[] = 'ACL SAVE';
        }

        $script = implode("\n", [
            'export REDISCLI_AUTH="$(cat /ops/auth)"',
            'redis-cli '.$endpoint->cliArgs($db).' < /ops/commands.txt > /tmp/out.txt 2>&1 || { cat /tmp/out.txt; exit 1; }',
            'cat /tmp/out.txt',
            "if grep -q -E '(^|\\(error\\) )(ERR|WRONGPASS|NOPERM|NOAUTH|WRONGTYPE)' /tmp/out.txt; then exit 1; fi",
            'echo RUNR_OK',
            '',
        ]);

        return new OpsTask($purpose, $image, $script, [
            'auth' => $endpoint->adminPassword,
            'commands.txt' => implode("\n", $commands)."\n",
        ]);
    }

    /**
     * Memory + key count per binding: "USAGE <binding id> <bytes> <keys>" lines.
     *
     * @param  list<array{id: string, db: int, pattern: string}>  $targets
     */
    public static function usage(RedisEndpoint $endpoint, string $image, array $targets): OpsTask
    {
        $lines = array_map(fn ($t) => "{$t['id']} {$t['db']} {$t['pattern']}", $targets);

        $script = implode("\n", [
            'export REDISCLI_AUTH="$(cat /ops/auth)"',
            'while read -r id db pattern; do',
            '  [ -z "$id" ] && continue',
            '  out=$(redis-cli '.$endpoint->cliArgs().' -n "$db" --raw --eval /ops/usage.lua , "$pattern" | tr \'\n\' \' \')',
            '  set -- $out',
            '  echo "USAGE $id ${1:-0} ${2:-0}"',
            'done < /ops/targets',
            'echo RUNR_OK',
            '',
        ]);

        return new OpsTask('kv-usage', $image, $script, [
            'auth' => $endpoint->adminPassword,
            'usage.lua' => self::USAGE_LUA,
            'targets' => implode("\n", $lines)."\n",
        ]);
    }

    /** Delete every key of a pattern, then run $after (e.g. ACL DELUSER). */
    public static function purge(RedisEndpoint $endpoint, string $image, string $pattern, int $db, array $after = []): OpsTask
    {
        if ($endpoint->persistAcl && self::touchesAcl($after)) {
            $after[] = 'ACL SAVE';
        }

        $script = implode("\n", [
            'set -e',
            'export REDISCLI_AUTH="$(cat /ops/auth)"',
            'n=$(redis-cli '.$endpoint->cliArgs($db).' --raw --eval /ops/purge.lua , '.OpsTask::quote($pattern).')',
            'echo "PURGED $n"',
            'if [ -s /ops/commands.txt ]; then',
            '  redis-cli '.$endpoint->cliArgs($db).' < /ops/commands.txt > /tmp/out.txt 2>&1 || { cat /tmp/out.txt; exit 1; }',
            '  cat /tmp/out.txt',
            "  if grep -q -E '(^|\\(error\\) )(ERR|NOPERM|NOAUTH)' /tmp/out.txt; then exit 1; fi",
            'fi',
            'echo RUNR_OK',
            '',
        ]);

        return new OpsTask('kv-purge', $image, $script, [
            'auth' => $endpoint->adminPassword,
            'purge.lua' => self::PURGE_LUA,
            'commands.txt' => $after ? implode("\n", $after)."\n" : '',
        ]);
    }

    /**
     * ACL support and server version of an endpoint: "ACL_SUPPORTED yes|no", "REDIS_VERSION x.y.z"
     * (the version an organization import compares KV dumps with, docs/org-export.md#binding-data).
     */
    public static function probe(RedisEndpoint $endpoint, string $image): OpsTask
    {
        $args = (new RedisEndpoint($endpoint->host, $endpoint->port, $endpoint->adminPassword, false, false, $endpoint->mode))->cliArgs();
        $script = implode("\n", [
            'export REDISCLI_AUTH="$(cat /ops/auth)"',
            'pong=$(redis-cli '.$args.' PING 2>&1)',
            'echo "PING $pong"',
            'case "$pong" in *PONG*) ;; *) exit 1 ;; esac',
            'who=$(redis-cli '.$args.' ACL WHOAMI 2>&1)',
            'case "$who" in *default*) echo "ACL_SUPPORTED yes" ;; *) echo "ACL_SUPPORTED no" ;; esac',
            'ver=$(redis-cli '.$args.' INFO server 2>/dev/null | tr -d \'\r\' | sed -n \'s/^redis_version://p\' | head -n 1)',
            'echo "REDIS_VERSION ${ver:-unknown}"',
            'echo RUNR_OK',
            '',
        ]);

        return new OpsTask('kv-probe', $image, $script, ['auth' => $endpoint->adminPassword]);
    }

    /** The commands change ACL users (SETUSER / DELUSER) — reads like GETUSER need no ACL SAVE */
    private static function touchesAcl(array $commands): bool
    {
        foreach ($commands as $command) {
            if (preg_match('/^ACL\s+(SETUSER|DELUSER)\b/i', ltrim($command))) {
                return true;
            }
        }

        return false;
    }
}
