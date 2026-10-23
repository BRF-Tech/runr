<?php

namespace App\Services\OrgTransfer\Region;

use App\Models\Binding;
use App\Services\Bindings\BindingDrivers;
use App\Services\Bindings\DataServices\KvService;
use App\Services\Bindings\Kv\RedisEndpoint;
use App\Services\Bindings\Objects\Rclone;

/**
 * The tool containers that dump a binding's data in its region and restore it into another
 * binding (docs/org-export.md#binding-data) — always with the binding's own credentials, except
 * for KV, where listing a binding's keys needs the instance's admin user (binding users may not
 * SCAN):
 *
 *  - postgres  pg_dump custom format of the binding's database; restored with pg_restore
 *              (--no-owner --no-privileges, one transaction) as the new binding's owner
 *  - kv        "kv_dump_v1": one line per key — hex key without the binding's prefix, PTTL, hex
 *              DUMP payload — read in SCAN batches (a short script per batch, so Redis is never
 *              blocked for long); restored with RESTORE … REPLACE under the new binding's prefix.
 *              DUMP payloads need a Redis of the same or a newer version
 *  - object    "object_tar_v1": a tar of the bucket's objects (rclone copy); restored by copying
 *              them into the new bucket
 *
 * External bindings have no data of their own (their values are secrets).
 */
class BindingDumps
{
    /** type => [file extension, format] */
    public const FORMATS = [
        'postgres' => ['dump', 'pg_dump_custom'],
        'kv' => ['kvdump', 'kv_dump_v1'],
        'object' => ['tar', 'object_tar_v1'],
    ];

    /** SCAN batch of the KV dump: [next cursor, "hexkey pttl hexdump" lines…] */
    public const KV_DUMP_LUA = <<<'LUA'
local function hex(s) return (s:gsub('.', function(c) return string.format('%02x', string.byte(c)) end)) end
local r = redis.call('SCAN', ARGV[1], 'MATCH', ARGV[2], 'COUNT', 200)
local out = {r[1]}
local skip = tonumber(ARGV[3])
for _, k in ipairs(r[2]) do
  local d = redis.call('DUMP', k)
  if d then
    out[#out + 1] = hex(string.sub(k, skip + 1)) .. ' ' .. redis.call('PTTL', k) .. ' ' .. hex(d)
  end
end
return out
LUA;

    /** One key back: ARGV prefix, hex key, PTTL (< 0: none), hex payload. Quoted for redis-cli. */
    public const KV_RESTORE_LUA = "local function u(h) return (h:gsub('..', function(c) return string.char(tonumber(c, 16)) end)) end "
        .'local t = tonumber(ARGV[3]) if t < 0 then t = 0 end '
        ."redis.call('RESTORE', ARGV[1] .. u(ARGV[2]), t, u(ARGV[4]), 'REPLACE') return 1";

    public function __construct(private BindingDrivers $drivers, private KvService $kv) {}

    public function supports(Binding $binding): bool
    {
        return isset(self::FORMATS[$binding->type]) && $binding->region !== null;
    }

    public static function extension(string $type): string
    {
        return self::FORMATS[$type][0] ?? 'bin';
    }

    public static function format(string $type): ?string
    {
        return self::FORMATS[$type][1] ?? null;
    }

    /** The file name in the transfer pod. */
    public static function fileName(string $type): string
    {
        return 'data.'.self::extension($type);
    }

    /** Tool that writes the binding's data to /work/out/data.<ext>. */
    public function dumpTool(Binding $binding): TransferTool
    {
        $out = '/work/out/'.self::fileName($binding->type);

        return match ($binding->type) {
            'postgres' => new TransferTool(
                (string) config('bindings.postgres.image'),
                TransferTool::wrap(implode("\n", [
                    'set -a; . /ops/pg.env; set +a',
                    'pg_dump --format=custom --no-owner --no-privileges --file='.TransferTool::quote($out).' || fail "pg_dump failed"',
                ])),
                ['pg.env' => self::envFile($this->drivers->for($binding)->env($binding), ['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'])],
            ),
            'kv' => $this->kvDump($binding, $out),
            'object' => $this->objectTool($binding, implode("\n", [
                'mkdir -p /work/stage',
                'rclone copy "t:$S3_BUCKET" /work/stage || fail "rclone copy from the bucket failed"',
                'tar -cf '.TransferTool::quote($out).' -C /work/stage . || fail "tar failed"',
                'rm -rf /work/stage',
            ])),
            default => throw new RegionTransferException("No data dump for {$binding->type} bindings."),
        };
    }

    /** Tool that restores /work/in/data.<ext> into $binding once the agent marked it ready. */
    public function restoreTool(Binding $binding): TransferTool
    {
        $in = '/work/in/'.self::fileName($binding->type);
        $wait = 'while [ ! -f /work/in/.ready ]; do [ -f /work/.failed ] && exit 1; sleep 2; done';

        return match ($binding->type) {
            'postgres' => new TransferTool(
                (string) config('bindings.postgres.image'),
                TransferTool::wrap(implode("\n", [
                    $wait,
                    'set -a; . /ops/pg.env; set +a',
                    'pg_restore --no-owner --no-privileges --single-transaction --exit-on-error --dbname="$PGDATABASE" '.TransferTool::quote($in).' > /tmp/restore.log 2>&1 || fail "pg_restore failed: $(tail -c 1500 /tmp/restore.log)"',
                ])),
                ['pg.env' => self::envFile($this->drivers->for($binding)->env($binding), ['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'])],
            ),
            'kv' => $this->kvRestore($binding, $in, $wait),
            'object' => $this->objectTool($binding, implode("\n", [
                $wait,
                'mkdir -p /work/stage',
                'tar -xf '.TransferTool::quote($in).' -C /work/stage || fail "the object archive could not be unpacked"',
                'rclone copy /work/stage "t:$S3_BUCKET" || fail "rclone copy into the bucket failed"',
                'rm -rf /work/stage',
            ])),
            default => throw new RegionTransferException("No data restore for {$binding->type} bindings."),
        };
    }

    /**
     * Tool that copies /work/in/<file> into the object binding's bucket as $key (an export
     * written to an object binding of the organization).
     */
    public function bucketUploadTool(Binding $binding, string $file, string $key): TransferTool
    {
        return $this->objectTool($binding, implode("\n", [
            'while [ ! -f /work/in/.ready ]; do [ -f /work/.failed ] && exit 1; sleep 2; done',
            'rclone copyto '.TransferTool::quote('/work/in/'.$file).' "t:$S3_BUCKET/"'.TransferTool::quote($key).' || fail "rclone copy into the bucket failed"',
        ]));
    }

    /**
     * Tool that copies the object $key of the object binding's bucket to /work/out/<file> (an
     * import read straight from an object binding of the organization); the agent serves it, with
     * its size and SHA-256, for RunR to download in ranges.
     */
    public function bucketDownloadTool(Binding $binding, string $key, string $file): TransferTool
    {
        $out = TransferTool::quote('/work/out/'.$file);

        return $this->objectTool($binding, implode("\n", [
            'rclone copyto "t:$S3_BUCKET/"'.TransferTool::quote($key).' '.$out.' > /tmp/copy.log 2>&1 || fail "the object could not be read from the bucket: $(tail -c 300 /tmp/copy.log)"',
            '[ -s '.$out.' ] || fail "the object is not in the bucket or is empty"',
        ]));
    }

    private function kvDump(Binding $binding, string $out): TransferTool
    {
        [$endpoint, $db, $prefix] = $this->kvTarget($binding);

        return new TransferTool(
            (string) config('bindings.kv.image'),
            TransferTool::wrap(implode("\n", [
                'export REDISCLI_AUTH="$(cat /ops/auth)"',
                'pattern="$(cat /ops/pattern)"',
                'skip='.strlen($prefix),
                ': > '.TransferTool::quote($out),
                'cursor=0',
                'while :; do',
                '  redis-cli '.$endpoint->cliArgs($db).' --raw --eval /ops/dump.lua , "$cursor" "$pattern" "$skip" > /tmp/batch 2>&1 || fail "redis-cli failed: $(head -c 300 /tmp/batch)"',
                '  if grep -q -E \'^(ERR|NOPERM|NOAUTH|WRONGPASS)\' /tmp/batch; then fail "redis: $(head -c 300 /tmp/batch)"; fi',
                '  cursor=$(head -n 1 /tmp/batch)',
                '  tail -n +2 /tmp/batch >> '.TransferTool::quote($out),
                '  [ "$cursor" = "0" ] && break',
                'done',
                // The server version goes with the dump (the agent's result): DUMP payloads restore
                // into the same or a newer Redis only, the import's plan compares it with the target
                'ver=$(redis-cli '.$endpoint->cliArgs($db).' INFO server 2>/dev/null | tr -d \'\r\' | sed -n \'s/^redis_version://p\' | head -n 1)',
                'printf \'{"redis_version":"%s"}\' "$(printf \'%s\' "$ver" | tr -cd \'0-9.\')" > /work/.result',
                'mv /work/.result /work/.done',
            ])),
            ['auth' => $endpoint->adminPassword, 'pattern' => self::globEscape($prefix).'*', 'dump.lua' => self::KV_DUMP_LUA],
        );
    }

    private function kvRestore(Binding $binding, string $in, string $wait): TransferTool
    {
        [$endpoint, $db, $prefix] = $this->kvTarget($binding);

        return new TransferTool(
            (string) config('bindings.kv.image'),
            TransferTool::wrap(implode("\n", [
                $wait,
                'export REDISCLI_AUTH="$(cat /ops/auth)"',
                'prefix="$(cat /ops/prefix)"',
                'script="$(cat /ops/restore.lua)"',
                'while read -r key ttl payload; do',
                '  [ -z "$key" ] && continue',
                '  printf \'EVAL "%s" 0 %s %s %s %s\n\' "$script" "${prefix:-\"\"}" "$key" "$ttl" "$payload"',
                'done < '.TransferTool::quote($in).' | redis-cli '.$endpoint->cliArgs($db).' > /tmp/restore.log 2>&1 || fail "redis-cli failed: $(tail -c 300 /tmp/restore.log)"',
                'if grep -q -E \'(^|\(error\) )(ERR|NOPERM|NOAUTH|WRONGPASS|BUSYKEY)\' /tmp/restore.log; then fail "redis: $(grep -m 1 -E \'ERR|NOPERM|NOAUTH|WRONGPASS|BUSYKEY\' /tmp/restore.log | head -c 300)"; fi',
            ])),
            ['auth' => $endpoint->adminPassword, 'prefix' => $prefix, 'restore.lua' => self::KV_RESTORE_LUA],
        );
    }

    /** @return array{0: RedisEndpoint, 1: int, 2: string} endpoint, logical DB, key prefix */
    private function kvTarget(Binding $binding): array
    {
        $region = $binding->region ?? throw new RegionTransferException("Binding {$binding->name} has no region.");
        $endpoint = $this->kv->endpoint($region);

        return $binding->credential('mode') === 'db'
            ? [$endpoint, (int) $binding->credential('db'), '']
            : [$endpoint, 0, $binding->kvPrefix()];
    }

    private function objectTool(Binding $binding, string $body): TransferTool
    {
        $env = $this->drivers->for($binding)->env($binding);

        return new TransferTool(
            (string) config('bindings.object.client_image'),
            TransferTool::wrap(implode("\n", [
                'set -a; . /ops/rclone.env; . /ops/bucket.env; set +a',
                $body,
            ])),
            [
                'rclone.env' => Rclone::envFile((string) $env['S3_ENDPOINT'], (string) $env['S3_REGION'], (string) $env['S3_ACCESS_KEY_ID'], (string) $env['S3_SECRET_ACCESS_KEY'], 'Other', ($env['S3_FORCE_PATH_STYLE'] ?? 'true') === 'true'),
                'bucket.env' => self::envFile(['S3_BUCKET' => (string) $env['S3_BUCKET']], ['S3_BUCKET']),
            ],
        );
    }

    /**
     * KEY='value' lines a POSIX shell sources (values single-quoted).
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private static function envFile(array $values, array $keys): string
    {
        $lines = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                $lines[] = $key.'='.TransferTool::quote((string) $values[$key]);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** A literal prefix in a SCAN MATCH pattern. */
    private static function globEscape(string $value): string
    {
        return preg_replace('/([\\\\*?\[\]])/', '\\\\$1', $value) ?? $value;
    }
}
